<?php

use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;

it('is prunable', function () {
    IngestRow::factory()->create(['created_at' => now()->subMonths(2)]);
    IngestRow::factory()->create(['created_at' => now()]);

    $this->assertDatabaseCount('ingest_rows', 2);

    $this->artisan('model:prune', ['--model' => IngestRow::class]);

    $this->assertDatabaseCount('ingest_rows', 1);
});

it('belongs to an ingest run', function () {
    $run = IngestRun::factory()->create();
    $row = IngestRow::factory()->create(['ingest_run_id' => $run->id]);

    expect($row->ingestRun)->toBeInstanceOf(IngestRun::class);
    expect($row->ingestRun->id)->toBe($run->id);
});