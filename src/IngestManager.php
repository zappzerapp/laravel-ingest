<?php

declare(strict_types=1);

namespace LaravelIngest;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Events\IngestRunCompleted;
use LaravelIngest\Events\IngestRunFailed;
use LaravelIngest\Events\IngestRunStarted;
use LaravelIngest\Exceptions\DefinitionNotFoundException;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Exceptions\NoFailedRowsException;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\Jobs\ProcessIngestChunkJob;
use LaravelIngest\Models\IngestRun;
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
     * @throws SourceException
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

    /** @throws DefinitionNotFoundException */
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
     * @throws NoFailedRowsException
     * @throws SourceException
     */
    public function retry(IngestRun $originalRun, ?Authenticatable $user = null, bool $isDryRun = false): IngestRun
    {
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
            'total_rows' => $originalRun->failed_rows,
        ]);

        IngestRunStarted::dispatch($newRun);

        try {
            $definition = $this->getDefinition($originalRun->importer);
            $config = $definition->getConfig();

            $failedRowsData = [];
            $originalRun->rows()
                ->where('status', 'failed')
                ->chunkById(1000, function ($rows) use (&$failedRowsData) {
                    foreach ($rows as $row) {
                        $failedRowsData[] = $row->data;
                    }
                });

            $this->dispatchBatch($newRun, $config, $failedRowsData, $isDryRun);

        } catch (Throwable $e) {
            $this->handleFailure($newRun, $e);
            throw $e;
        }

        return $newRun;
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
        $batchJobs = $this->createBatchJobs($ingestRun, $config, $rows, $isDryRun);

        $this->updateTotalRowsIfRetry($ingestRun, $rows);

        if (empty($batchJobs)) {
            $this->handleEmptyBatch($ingestRun, $cleanupCallback);

            return;
        }

        $this->dispatchBatchJobs($ingestRun, $batchJobs, $cleanupCallback);
    }

    protected function handleFailure(IngestRun $ingestRun, Throwable $e): void
    {
        $ingestRun->update([
            'status' => IngestStatus::FAILED,
            'summary' => [
                'errors' => [
                    [
                        'message' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
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
        $ingestRun->update([
            'total_rows' => $sourceHandler->getTotalRows() ?? 0,
            'processed_filepath' => $sourceHandler->getProcessedFilePath(),
        ]);
    }

    private function createBatchJobs(IngestRun $ingestRun, IngestConfig $config, iterable $rows, bool $isDryRun): array
    {
        $batchJobs = [];
        $chunk = [];
        $rowCounter = 1;

        foreach ($rows as $row) {
            $chunk[] = ['number' => $rowCounter++, 'data' => $row];

            if (count($chunk) >= $config->chunkSize) {
                $batchJobs[] = new ProcessIngestChunkJob($ingestRun, $config, $chunk, $isDryRun);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $batchJobs[] = new ProcessIngestChunkJob($ingestRun, $config, $chunk, $isDryRun);
        }

        return $batchJobs;
    }

    private function updateTotalRowsIfRetry(IngestRun $ingestRun, iterable $rows): void
    {
        if ($ingestRun->parent_id) {
            $totalRows = is_countable($rows) ? count($rows) : iterator_count($rows);
            if ($ingestRun->total_rows !== $totalRows) {
                $ingestRun->update(['total_rows' => $totalRows]);
            }
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
    private function dispatchBatchJobs(IngestRun $ingestRun, array $batchJobs, ?callable $cleanupCallback): void
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
    }
}
