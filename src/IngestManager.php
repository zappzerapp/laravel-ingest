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

        $originalFilename = null;
        if ($payload instanceof UploadedFile) {
            $originalFilename = $payload->getClientOriginalName();
        } elseif (is_string($payload)) {
            $originalFilename = basename($payload);
        }

        $ingestRun = IngestRun::create([
            'importer' => $importer,
            'user_id' => $user?->getAuthIdentifier(),
            'status' => IngestStatus::PROCESSING,
            'original_filename' => $originalFilename,
        ]);

        IngestRunStarted::dispatch($ingestRun);

        try {
            $rowGenerator = $sourceHandler->read($config, $payload);

            $ingestRun->update([
                'total_rows' => $sourceHandler->getTotalRows() ?? 0,
                'processed_filepath' => $sourceHandler->getProcessedFilePath(),
            ]);

            $this->dispatchBatch($ingestRun, $config, $rowGenerator, $isDryRun, function () use ($sourceHandler) {
                $sourceHandler->cleanup();
            });

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

            $failedRowsCursor = $originalRun->rows()
                ->where('status', 'failed')
                ->cursor()
                ->map(fn($row) => $row->data);

            $this->dispatchBatch($newRun, $config, $failedRowsCursor, $isDryRun);

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

        if ($ingestRun->parent_id && $ingestRun->total_rows !== ($rowCounter - 1)) {
            $ingestRun->update(['total_rows' => $rowCounter - 1]);
        }

        if (empty($batchJobs)) {
            $ingestRun->finalize();
            if ($cleanupCallback) {
                $cleanupCallback();
            }
            IngestRunCompleted::dispatch($ingestRun);

            return;
        }

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

    protected function handleFailure(IngestRun $ingestRun, Throwable $e): void
    {
        $summary = [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        $ingestRun->update([
            'status' => IngestStatus::FAILED,
            'summary' => $summary,
        ]);

        IngestRunFailed::dispatch($ingestRun, $e);
    }
}
