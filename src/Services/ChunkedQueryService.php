<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Illuminate\Support\Collection;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;

readonly class ChunkedQueryService
{
    public function __construct(
        private MemoryLimitService $memoryService
    ) {}

    public function getFailedRowsChunked(int $runId, int $chunkSize = 1000): Collection
    {
        $results = collect();

        IngestRow::where('ingest_run_id', $runId)
            ->where('status', 'failed')
            ->orderBy('row_number')
            ->chunk($chunkSize, function ($chunk) use ($results) {
                foreach ($chunk as $row) {
                    $results->push($row->data);
                }
            });

        return $results;
    }

    public function getFailedRowsCount(int $runId): int
    {
        return IngestRow::where('ingest_run_id', $runId)
            ->where('status', 'failed')
            ->count();
    }

    public function processInBatches(iterable $rows, int $batchSize, callable $processor, int $memoryLimitMb = 256): void
    {
        $this->memoryService->processInChunks($rows, $batchSize, $processor, $memoryLimitMb);
    }

    public function batchInsertRows(array $rowsData, int $batchSize = 500): void
    {
        $chunks = array_chunk($rowsData, $batchSize);

        foreach ($chunks as $chunk) {
            IngestRow::insert($chunk);
        }
    }

    public function preloadRelationsBatched(array $chunk, IngestConfig $config): array
    {
        $cache = [];

        foreach ($config->relations as $sourceField => $relationConfig) {
            $values = collect($chunk)
                ->map(fn($item) => data_get($item, 'data.' . $sourceField))
                ->filter()
                ->unique()
                ->values()
                ->take(1000)
                ->toArray();

            if (!empty($values)) {
                $relatedModelClass = $relationConfig['model'];
                $lookupKey = $relationConfig['key'];
                $pkName = (new $relatedModelClass())->getKeyName();

                $results = $relatedModelClass::query()
                    ->whereIn($lookupKey, $values)
                    ->get([$pkName, $lookupKey]);

                $cache[$sourceField] = $results->pluck($pkName, $lookupKey)->toArray();
            }
        }

        return $cache;
    }
}
