<?php

declare(strict_types=1);

namespace LaravelIngest\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelIngest\Contracts\FlowEngineInterface;
use LaravelIngest\Events\ChunkProcessed;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;

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
        public bool $isDryRun = false,
        private ?FlowEngineInterface $flowEngine = null
    ) {}

    public function handle(?RowProcessor $rowProcessor = null, ?FlowEngineInterface $flowEngine = null): void
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $this->checkMemoryUsage();

        $engine = $flowEngine ?? $this->flowEngine ?? app(FlowEngineInterface::class);

        $pipeline = $engine->build($this->config, $this->chunk, $this->ingestRun, $this->isDryRun);
        $engine->execute($pipeline);

        $results = $this->calculateResults();

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

    private function calculateResults(): array
    {
        $processed = count($this->chunk);
        $rowNumbers = array_map(fn($item) => $item['number'], $this->chunk);

        $stats = \LaravelIngest\Models\IngestRow::query()
            ->where('ingest_run_id', $this->ingestRun->id)
            ->whereIn('row_number', $rowNumbers)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'processed' => $processed,
            'successful' => $stats['success'] ?? 0,
            'failed' => $stats['failed'] ?? 0,
        ];
    }

    private function forceGarbageCollection(): void
    {
        gc_collect_cycles();

        $this->chunk = [];
    }
}
