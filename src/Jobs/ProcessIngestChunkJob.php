<?php

declare(strict_types=1);

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
use Throwable;

class ProcessIngestChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public IngestRun $ingestRun,
        public IngestConfig $config,
        public array $chunk,
        public bool $isDryRun = false
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(RowProcessor $rowProcessor): void
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $this->checkMemoryUsage();

        $results = $rowProcessor->processChunk($this->ingestRun, $this->config, $this->chunk, $this->isDryRun);

        $this->ingestRun->increment('processed_rows', $results['processed']);
        $this->ingestRun->increment('successful_rows', $results['successful']);
        $this->ingestRun->increment('failed_rows', $results['failed']);

        ChunkProcessed::dispatch($this->ingestRun, $results);

        $this->forceGarbageCollection();
    }

    protected function checkMemoryUsage(): void
    {
        $memoryLimit = $this->getMemoryLimitInBytes();
        $currentMemory = $this->getCurrentMemoryUsage();

        if ($currentMemory > ($memoryLimit * 0.8)) {
            gc_collect_cycles();
        }
    }

    protected function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');

        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        return ini_parse_quantity($memoryLimit);
    }

    protected function getCurrentMemoryUsage(): int
    {
        return memory_get_usage(true);
    }

    private function forceGarbageCollection(): void
    {
        gc_collect_cycles();

        $this->chunk = [];
    }
}
