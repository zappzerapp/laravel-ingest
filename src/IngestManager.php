<?php

declare(strict_types=1);

namespace LaravelIngest;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Events\IngestRunCompleted;
use LaravelIngest\Events\IngestRunFailed;
use LaravelIngest\Events\IngestRunStarted;
use LaravelIngest\Exceptions\ConcurrencyException;
use LaravelIngest\Exceptions\DefinitionNotFoundException;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Exceptions\NoFailedRowsException;
use LaravelIngest\Jobs\ProcessIngestChunkJob;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\ErrorMessageService;
use LaravelIngest\Sources\SourceHandlerFactory;
use Throwable;

class IngestManager
{
    public function __construct(
        protected array $definitions,
        protected SourceHandlerFactory $sourceHandlerFactory
    ) {}

    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * @throws Throwable
     * @throws InvalidConfigurationException
     * @throws DefinitionNotFoundException
     */
    public function start(
        string $importer,
        mixed $payload = null,
        ?Authenticatable $user = null,
        bool $isDryRun = false
    ): IngestRun {
        $definition = $this->getDefinition($importer);
        $config = $definition->getConfig();
        $sourceHandler = $this->sourceHandlerFactory->make($config->sourceType);

        $originalFilename = $this->extractOriginalFilename($payload);
        $ingestRun = $this->createIngestRun($importer, $user, $originalFilename);

        IngestRunStarted::dispatch($ingestRun);

        try {
            $rowGenerator = $sourceHandler->read($config, $payload);
            $this->updateIngestRunWithMetadata($ingestRun, $sourceHandler);
            $this->dispatchBatch($ingestRun, $config, $rowGenerator, $isDryRun, fn() => $sourceHandler->cleanup());
        } catch (Throwable $e) {
            $this->handleFailure($ingestRun, $e);
            $sourceHandler->cleanup();
            throw $e;
        }

        return $ingestRun;
    }

    /**
     * @throws DefinitionNotFoundException
     */
    public function getDefinition(string $slug): IngestDefinition
    {
        if (!isset($this->definitions[$slug])) {
            throw new DefinitionNotFoundException(
                "No importer found with the slug '{$slug}'. " .
                "Please check your spelling or run 'php artisan ingest:list' to see available importers."
            );
        }

        return $this->definitions[$slug];
    }

    /**
     * @throws Throwable
     * @throws DefinitionNotFoundException
     * @throws ConcurrencyException
     * @throws NoFailedRowsException
     */
    public function retry(IngestRun $originalRun, ?Authenticatable $user = null, bool $isDryRun = false): IngestRun
    {
        $lock = Cache::lock('retry-ingest-run-' . $originalRun->id, 10);
        if (!$lock->get()) {
            throw ConcurrencyException::duplicateRetryAttempt($originalRun->id);
        }

        try {
            if ($originalRun->failed_rows === 0) {
                throw new NoFailedRowsException('The original run has no failed rows to retry.');
            }

            $newRun = IngestRun::create([
                'parent_id' => $originalRun->id,
                'retried_from_run_id' => $originalRun->id,
                'importer' => $originalRun->importer,
                'user_id' => $user?->getAuthIdentifier(),
                'status' => IngestStatus::PROCESSING,
                'original_filename' => $originalRun->original_filename,
            ]);

            IngestRunStarted::dispatch($newRun);

            try {
                $definition = $this->getDefinition($originalRun->importer);
                $config = $definition->getConfig();

                $failedRowsData = $originalRun->rows()->where('status', 'failed')->cursor()->map(fn($row) => $row->data);

                $this->dispatchBatch($newRun, $config, $failedRowsData, $isDryRun);
            } catch (Throwable $e) {
                $this->handleFailure($newRun, $e);
                throw $e;
            }

            return $newRun;
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws Throwable
     */
    protected function dispatchBatch(
        IngestRun $ingestRun,
        IngestConfig $config,
        iterable $rows,
        bool $isDryRun,
        ?callable $cleanupCallback = null
    ): void {
        $rowCounter = 1;
        $totalRows = 0;
        $chunk = [];
        $batch = null;

        foreach ($rows as $row) {
            $totalRows++;
            $chunk[] = ['number' => $rowCounter++, 'data' => $row];

            if (count($chunk) >= $config->chunkSize) {
                $batch = $this->addChunkToBatch($batch, $ingestRun, $config, $chunk, $isDryRun, $cleanupCallback);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $batch = $this->addChunkToBatch($batch, $ingestRun, $config, $chunk, $isDryRun, $cleanupCallback);
        }

        $this->updateTotalRows($ingestRun, $totalRows);

        if ($batch === null) {
            $this->handleEmptyBatch($ingestRun, $cleanupCallback);
        }
    }

    protected function handleFailure(IngestRun $ingestRun, Throwable $e): void
    {
        ErrorMessageService::setEnvironment(app()->environment('production'));
        $sanitizedMessage = ErrorMessageService::sanitize($e->getMessage());

        $ingestRun->update([
            'status' => IngestStatus::FAILED,
            'summary' => [
                'errors' => [
                    [
                        'message' => $sanitizedMessage,
                        'exception' => get_class($e),
                    ],
                ],
                'warnings' => [],
                'meta' => [],
            ],
        ]);

        IngestRunFailed::dispatch($ingestRun, $e);
    }

    private function extractOriginalFilename(mixed $payload): ?string
    {
        if ($payload instanceof UploadedFile) {
            return $payload->getClientOriginalName();
        }

        if (is_string($payload)) {
            return basename($payload);
        }

        return null;
    }

    private function createIngestRun(string $importer, ?Authenticatable $user, ?string $originalFilename): IngestRun
    {
        return IngestRun::create([
            'importer' => $importer,
            'user_id' => $user?->getAuthIdentifier(),
            'status' => IngestStatus::PROCESSING,
            'original_filename' => $originalFilename,
        ]);
    }

    private function updateIngestRunWithMetadata(IngestRun $ingestRun, mixed $sourceHandler): void
    {
        $totalRows = $sourceHandler->getTotalRows();
        $ingestRun->update([
            'processed_filepath' => $sourceHandler->getProcessedFilePath(),
        ]);

        if ($totalRows !== null) {
            $ingestRun->update(['total_rows' => $totalRows]);
        }
    }

    private function updateTotalRows(IngestRun $ingestRun, int $totalRows): void
    {
        if ($ingestRun->total_rows !== $totalRows) {
            $ingestRun->update(['total_rows' => $totalRows]);
        }
    }

    private function handleEmptyBatch(IngestRun $ingestRun, ?callable $cleanupCallback): void
    {
        $ingestRun->finalize();

        if ($cleanupCallback) {
            $cleanupCallback();
        }

        IngestRunCompleted::dispatch($ingestRun);
    }

    /**
     * @throws Throwable
     */
    private function dispatchBatchJobs(IngestRun $ingestRun, array $batchJobs, ?callable $cleanupCallback): Batch
    {
        $queueConnection = Config::get('ingest.queue.connection');
        $queueName = Config::get('ingest.queue.name');

        $batch = Bus::batch($batchJobs)
            ->then(function () use ($ingestRun, $cleanupCallback) {
                $ingestRun->finalize();
                if ($cleanupCallback) {
                    $cleanupCallback();
                }
                IngestRunCompleted::dispatch($ingestRun);
            })
            ->catch(function (Throwable $e) use ($ingestRun, $cleanupCallback) {
                $ingestRun->update(['status' => IngestStatus::FAILED]);
                if ($cleanupCallback) {
                    $cleanupCallback();
                }
                IngestRunFailed::dispatch($ingestRun, $e);
            })
            ->onConnection($queueConnection)
            ->onQueue($queueName)
            ->dispatch();

        $ingestRun->update(['batch_id' => $batch->id]);

        return $batch;
    }

    /**
     * @throws Throwable
     */
    private function addChunkToBatch(
        ?Batch $batch,
        IngestRun $ingestRun,
        IngestConfig $config,
        array $chunk,
        bool $isDryRun,
        ?callable $cleanupCallback
    ): Batch {
        $job = new ProcessIngestChunkJob($ingestRun, $config, $chunk, $isDryRun);

        if ($batch === null) {
            return $this->dispatchBatchJobs($ingestRun, [$job], $cleanupCallback);
        }

        $batch->add([$job]);

        return $batch;
    }
}
