<?php

namespace LaravelIngest\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelIngest\Events\ChunkProcessed;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;

class ProcessIngestChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public IngestRun    $ingestRun,
        public IngestConfig $config,
        public array        $chunk,
        public bool         $isDryRun = false
    )
    {
    }

    public function handle(RowProcessor $rowProcessor): void
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $results = $rowProcessor->processChunk($this->ingestRun, $this->config, $this->chunk, $this->isDryRun);

        $this->ingestRun->increment('processed_rows', $results['processed']);
        $this->ingestRun->increment('successful_rows', $results['successful']);
        $this->ingestRun->increment('failed_rows', $results['failed']);

        ChunkProcessed::dispatch($this->ingestRun, $results);
    }
}