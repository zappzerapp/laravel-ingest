<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use LaravelIngest\Models\IngestRun;

it('can retrieve its batch object', function () {
    Bus::fake();

    $batch = Bus::batch([])->dispatch();
    $run = IngestRun::factory()->create(['batch_id' => $batch->id]);

    $retrievedBatch = $run->batch();

    expect($retrievedBatch)->not()->toBeNull();
    expect($retrievedBatch->id)->toBe($batch->id);
});

it('returns null if batch id is not set', function () {
    $run = IngestRun::factory()->create(['batch_id' => null]);

    expect($run->batch())->toBeNull();
});

it('can retrieve its original run via relationship', function () {
    $originalRun = IngestRun::factory()->create();
    $retryRun = IngestRun::factory()->create(['retried_from_run_id' => $originalRun->id]);

    $retrievedOriginal = $retryRun->originalRun;

    expect($retrievedOriginal)->toBeInstanceOf(IngestRun::class);
    expect($retrievedOriginal->id)->toBe($originalRun->id);
});
