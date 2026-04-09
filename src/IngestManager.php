<?php

declare(strict_types=1);

namespace LaravelIngest;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
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
use LaravelIngest\Models\IngestRow;
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
            throw DefinitionNotFoundException::forSlug($slug);
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
        return $this->executeWithRetryLock($originalRun, function () use ($originalRun, $user, $isDryRun) {
            $this->validateFailedRowsExist($originalRun);

            $newRun = $this->createRetryRun($originalRun, $user);
            IngestRunStarted::dispatch($newRun);

            try {
                $this->processRetryBatch($originalRun, $newRun, $isDryRun);
            } catch (Throwable $e) {
                $this->handleFailure($newRun, $e);
                throw $e;
            }

            return $newRun;
        });
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
        $batch = null;
        $totalRows = 0;
        $chunks = $this->chunkRows($rows, $config->chunkSize, $totalRows);

        foreach ($chunks as $chunk) {
            $batch = $this->addChunkToBatch($batch, $ingestRun, $config, $chunk, $isDryRun, $cleanupCallback);
        }

        $this->updateTotalRows($ingestRun, $totalRows);
        $this->finalizeBatchDispatch($ingestRun, $batch, $cleanupCallback);
    }

    protected function handleFailure(IngestRun $ingestRun, Throwable $e): void
    {
        $this->logFailure($ingestRun, $e);

        $ingestRun->update([
            'status' => IngestStatus::FAILED,
            'summary' => $this->buildFailureSummary($e),
        ]);

        IngestRunFailed::dispatch($ingestRun, $e);
    }

    /**
     * @throws ConcurrencyException
     */
    private function executeWithRetryLock(IngestRun $originalRun, callable $callback): mixed
    {
        $lock = Cache::lock('retry-ingest-run-' . $originalRun->id, 10);
        if (!$lock->get()) {
            throw ConcurrencyException::duplicateRetryAttempt($originalRun->id);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws NoFailedRowsException
     */
    private function validateFailedRowsExist(IngestRun $originalRun): void
    {
        if ($originalRun->failed_rows === 0) {
            throw new NoFailedRowsException('The original run has no failed rows to retry.');
        }
    }

    private function createRetryRun(IngestRun $originalRun, ?Authenticatable $user): IngestRun
    {
        return IngestRun::create([
            'parent_id' => $originalRun->id,
            'retried_from_run_id' => $originalRun->id,
            'importer' => $originalRun->importer,
            'user_id' => $user?->getAuthIdentifier(),
            'status' => IngestStatus::PROCESSING,
            'original_filename' => $originalRun->original_filename,
        ]);
    }

    /**
     * @throws DefinitionNotFoundException
     * @throws Throwable
     */
    private function processRetryBatch(IngestRun $originalRun, IngestRun $newRun, bool $isDryRun): void
    {
        $definition = $this->getDefinition($originalRun->importer);
        $config = $definition->getConfig();

        $failedRowsData = $originalRun->rows()->where('status', 'failed')->cursor()
            ->map(fn($row) => assert($row instanceof IngestRow) ? $row->data : []);

        $this->dispatchBatch($newRun, $config, $failedRowsData, $isDryRun);
    }

    /**
     * @param-out int $totalRows
     *
     * @return iterable<array{number: int, data: mixed}[]>
     */
    private function chunkRows(iterable $rows, int $chunkSize, int &$totalRows): iterable
    {
        $rowCounter = 1;
        $chunk = [];
        $totalRows = 0;

        foreach ($rows as $row) {
            $totalRows++;
            $chunk[] = ['number' => $rowCounter++, 'data' => $row];

            if (count($chunk) >= $chunkSize) {
                yield $chunk;
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            yield $chunk;
        }
    }

    private function finalizeBatchDispatch(IngestRun $ingestRun, ?Batch $batch, ?callable $cleanupCallback): void
    {
        if ($batch === null) {
            $this->handleEmptyBatch($ingestRun, $cleanupCallback);
        }
    }

    private function logFailure(IngestRun $ingestRun, Throwable $e): void
    {
        Log::error('Ingest run failed', [
            'run_id' => $ingestRun->id,
            'importer' => $ingestRun->importer,
            'user_id' => $ingestRun->user_id,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    /**
     * @return array{errors: array<int, array{message: string, exception: string}>, warnings: array<mixed>, meta: array<mixed>}
     */
    private function buildFailureSummary(Throwable $e): array
    {
        ErrorMessageService::setEnvironment(app()->environment('production'));
        $sanitizedMessage = ErrorMessageService::sanitize($e->getMessage());

        return [
            'errors' => [
                [
                    'message' => $sanitizedMessage,
                    'exception' => get_class($e),
                ],
            ],
            'warnings' => [],
            'meta' => [],
        ];
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
            'processed_filepath' => $sourceHandler->getProcessedFilePath(),
        ]);

        $totalRows = $sourceHandler->getTotalRows();
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
        $batch = Bus::batch($batchJobs)
            ->then($this->createBatchSuccessCallback($ingestRun, $cleanupCallback))
            ->catch($this->createBatchFailureCallback($ingestRun, $cleanupCallback))
            ->onConnection(Config::get('ingest.queue.connection'))
            ->onQueue(Config::get('ingest.queue.name'))
            ->dispatch();

        $ingestRun->update(['batch_id' => $batch->id]);

        return $batch;
    }

    private function createBatchSuccessCallback(IngestRun $ingestRun, ?callable $cleanupCallback): Closure
    {
        return function () use ($ingestRun, $cleanupCallback) {
            $ingestRun->finalize();
            if ($cleanupCallback) {
                $cleanupCallback();
            }
            IngestRunCompleted::dispatch($ingestRun);
        };
    }

    private function createBatchFailureCallback(IngestRun $ingestRun, ?callable $cleanupCallback): Closure
    {
        return function (Throwable $e) use ($ingestRun, $cleanupCallback) {
            $ingestRun->update(['status' => IngestStatus::FAILED]);
            if ($cleanupCallback) {
                $cleanupCallback();
            }
            IngestRunFailed::dispatch($ingestRun, $e);
        };
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
