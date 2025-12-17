<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use LaravelIngest\Enums\IngestStatus;
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

it('sets status to completed with errors when failures exist on finalization', function () {
    $run = IngestRun::factory()->create([
        'status' => IngestStatus::PROCESSING,
        'failed_rows' => 5,
        'successful_rows' => 95,
        'completed_at' => null,
    ]);

    $run->finalize();

    $run->refresh();

    expect($run->status)->toBe(IngestStatus::COMPLETED_WITH_ERRORS);
    expect($run->completed_at)->toBeInstanceOf(Carbon::class);
    expect($run->failed_rows)->toBe(5);
    expect($run->successful_rows)->toBe(95);
});

it('sets status to completed when no failures exist on finalization', function () {
    $run = IngestRun::factory()->create([
        'status' => IngestStatus::PROCESSING,
        'failed_rows' => 0,
        'successful_rows' => 100,
        'completed_at' => null,
    ]);

    $run->finalize();

    $run->refresh();

    expect($run->status)->toBe(IngestStatus::COMPLETED);
    expect($run->completed_at)->toBeInstanceOf(Carbon::class);
    expect($run->failed_rows)->toBe(0);
});

it('uses refreshed counts to determine final status', function () {
    $run = IngestRun::factory()->create([
        'status' => IngestStatus::PROCESSING,
        'failed_rows' => 0,
        'completed_at' => null,
    ]);

    IngestRun::where('id', $run->id)->update(['failed_rows' => 1]);

    $run->finalize();

    expect($run->status)->toBe(IngestStatus::COMPLETED_WITH_ERRORS);
});