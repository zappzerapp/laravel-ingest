<?php

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
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\Jobs\ProcessIngestChunkJob;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Sources\SourceHandlerFactory;
use Throwable;

class IngestManager
{
    public function __construct(protected array $definitions)
    {
    }

    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function start(
        string           $importerSlug,
        mixed            $payload = null,
        ?Authenticatable $user = null,
        bool             $isDryRun = false
    ): IngestRun
    {
        $definition = $this->getDefinition($importerSlug);
        $config = $definition->getConfig();
        $sourceHandlerFactory = new SourceHandlerFactory();
        $sourceHandler = $sourceHandlerFactory->make($config->sourceType);

        $ingestRun = IngestRun::create([
            'importer_slug' => $importerSlug,
            'user_id' => $user?->getAuthIdentifier(),
            'status' => IngestStatus::PROCESSING,
            'original_filename' => $payload instanceof UploadedFile ? $payload->getClientOriginalName() : null,
        ]);

        IngestRunStarted::dispatch($ingestRun);

        try {
            $rowGenerator = $sourceHandler->read($config, $payload);

            $ingestRun->update([
                'total_rows' => $sourceHandler->getTotalRows() ?? 0,
                'processed_filepath' => $sourceHandler->getProcessedFilePath()
            ]);

            $batchJobs = [];
            $chunk = [];
            $rowCounter = 1;

            $headersChecked = false;

            foreach ($rowGenerator as $row) {
                if (!$headersChecked && $config->keyedBy) {
                    if (!array_key_exists($config->keyedBy, $row)) {
                        throw new SourceException("The key column '{$config->keyedBy}' was not found in the source file headers.");
                    }
                    $headersChecked = true;
                }

                $chunk[] = ['number' => $rowCounter++, 'data' => $row];

                if (count($chunk) >= $config->chunkSize) {
                    $batchJobs[] = new ProcessIngestChunkJob($ingestRun, $config, $chunk, $isDryRun);
                    $chunk = [];
                }
            }

            if (!empty($chunk)) {
                $batchJobs[] = new ProcessIngestChunkJob($ingestRun, $config, $chunk, $isDryRun);
            }

            if (empty($batchJobs)) {
                $ingestRun->finalize();
                IngestRunCompleted::dispatch($ingestRun);
                $sourceHandler->cleanup();
                return $ingestRun;
            }

            $queueConnection = Config::get('ingest.queue.connection');
            $queueName = Config::get('ingest.queue.name');

            $batch = Bus::batch($batchJobs)
                ->then(function () use ($ingestRun, $sourceHandler) {
                    $ingestRun->finalize();
                    $sourceHandler->cleanup();
                    IngestRunCompleted::dispatch($ingestRun);
                })
                ->catch(function (Throwable $e) use ($ingestRun, $sourceHandler) {
                    $ingestRun->update(['status' => IngestStatus::FAILED]);
                    $sourceHandler->cleanup();
                    IngestRunFailed::dispatch($ingestRun, $e);
                })
                ->onConnection($queueConnection)
                ->onQueue($queueName)
                ->dispatch();

            $ingestRun->update(['batch_id' => $batch->id]);

        } catch (Throwable $e) {
            $ingestRun->update(['status' => IngestStatus::FAILED, 'summary' => ['error' => $e->getMessage()]]);
            $sourceHandler?->cleanup();
            IngestRunFailed::dispatch($ingestRun, $e);
            throw $e;
        }

        return $ingestRun;
    }

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
}