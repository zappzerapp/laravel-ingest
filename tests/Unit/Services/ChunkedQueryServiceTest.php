<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\ChunkedQueryService;

beforeEach(function () {
    IngestRow::query()->delete();
    IngestRun::query()->delete();
});

it('gets failed rows chunked', function () {
    $run = IngestRun::factory()->create(['failed_rows' => 2]);

    IngestRow::create([
        'ingest_run_id' => $run->id,
        'row_number' => 1,
        'status' => 'failed',
        'data' => ['name' => 'Row 1'],
    ]);

    IngestRow::create([
        'ingest_run_id' => $run->id,
        'row_number' => 2,
        'status' => 'failed',
        'data' => ['name' => 'Row 2'],
    ]);

    $service = new ChunkedQueryService(new LaravelIngest\Services\MemoryLimitService());
    $results = $service->getFailedRowsChunked($run->id, 100);

    expect($results)->toHaveCount(2);
});

it('processes rows in batches', function () {
    $rows = [[1], [2], [3]];
    $processed = [];

    $service = new ChunkedQueryService(new LaravelIngest\Services\MemoryLimitService());
    $service->processInBatches($rows, 2, function ($chunk) use (&$processed) {
        $processed[] = $chunk;
    }, 4096);

    expect($processed)->toHaveCount(2);
});

it('gets failed rows count', function () {
    $run = IngestRun::factory()->create();

    IngestRow::factory()->count(3)->create([
        'ingest_run_id' => $run->id,
        'status' => 'failed',
    ]);

    IngestRow::factory()->count(2)->create([
        'ingest_run_id' => $run->id,
        'status' => 'success',
    ]);

    $service = new ChunkedQueryService(new LaravelIngest\Services\MemoryLimitService());
    $count = $service->getFailedRowsCount($run->id);

    expect($count)->toBe(3);
});

it('batch inserts rows', function () {
    $run = IngestRun::factory()->create(['id' => 1, 'importer' => 'test']);

    $rowsData = [
        ['ingest_run_id' => $run->id, 'row_number' => 1, 'data' => json_encode(['a' => 1]), 'status' => 'success', 'created_at' => now(), 'updated_at' => now()],
        ['ingest_run_id' => $run->id, 'row_number' => 2, 'data' => json_encode(['b' => 2]), 'status' => 'success', 'created_at' => now(), 'updated_at' => now()],
    ];

    $service = new ChunkedQueryService(new LaravelIngest\Services\MemoryLimitService());
    $service->batchInsertRows($rowsData, 1);

    expect(IngestRow::count())->toBe(2);
});

it('preloads relations with empty config', function () {
    $chunk = [['data' => ['name' => 'Test']]];

    $mockConfig = Mockery::mock(IngestConfig::class);
    $mockConfig->shouldReceive('relations')->andReturn([]);

    $service = new ChunkedQueryService(new LaravelIngest\Services\MemoryLimitService());
    $cache = $service->preloadRelationsBatched($chunk, $mockConfig);

    expect($cache)->toBeArray();
});

it('preloads relations with populated config', function () {
    $relatedModel = IngestRun::factory()->create();

    $chunk = [['data' => ['run_id' => $relatedModel->id]]];

    $config = IngestConfig::for(IngestRow::class)
        ->relate('run_id', 'ingestRun', IngestRun::class, 'id');

    $service = new ChunkedQueryService(new LaravelIngest\Services\MemoryLimitService());
    $cache = $service->preloadRelationsBatched($chunk, $config);

    expect($cache)->toBeArray();
    expect($cache)->toHaveKey('run_id');
    expect($cache['run_id'])->toHaveKey($relatedModel->id);
    expect($cache['run_id'][$relatedModel->id])->toBe($relatedModel->id);
});
