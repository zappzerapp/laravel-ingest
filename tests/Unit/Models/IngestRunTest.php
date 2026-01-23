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

it('can retrieve its parent run via relationship', function () {
    $parentRun = IngestRun::factory()->create();
    $retryRun = IngestRun::factory()->create(['parent_id' => $parentRun->id]);

    $retrievedParent = $retryRun->parent;

    expect($retrievedParent)->toBeInstanceOf(IngestRun::class);
    expect($retrievedParent->id)->toBe($parentRun->id);
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

it('sets standardized summary structure on finalization when no summary exists', function () {
    $run = IngestRun::factory()->create([
        'status' => IngestStatus::PROCESSING,
        'summary' => null,
        'successful_rows' => 95,
        'failed_rows' => 5,
        'total_rows' => 100,
        'completed_at' => null,
    ]);

    $run->finalize();

    $run->refresh();

    expect($run->summary)->toBeArray();
    expect($run->summary)->toHaveKeys(['errors', 'warnings', 'meta']);
    expect($run->summary['errors'])->toBe([]);
    expect($run->summary['warnings'])->toBe([]);
    expect($run->summary['meta']['successful_rows'])->toBe(95);
    expect($run->summary['meta']['failed_rows'])->toBe(5);
    expect($run->summary['meta']['total_rows'])->toBe(100);
});

it('preserves existing summary on finalization if already set', function () {
    $existingSummary = [
        'errors' => [['message' => 'Custom error']],
        'warnings' => [['message' => 'Custom warning']],
        'meta' => ['custom_key' => 'custom_value'],
    ];

    $run = IngestRun::factory()->create([
        'status' => IngestStatus::PROCESSING,
        'summary' => $existingSummary,
        'successful_rows' => 100,
        'failed_rows' => 0,
        'completed_at' => null,
    ]);

    $run->finalize();

    $run->refresh();

    expect($run->summary)->toBe($existingSummary);
});
