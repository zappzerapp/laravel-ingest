<?php

declare(strict_types=1);

use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Exceptions\ConcurrencyException;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\ConcurrencyService;

beforeEach(function () {
    IngestRun::query()->delete();
    IngestRow::query()->delete();
});

it('increments counters with locked run', function () {
    $run = IngestRun::factory()->create([
        'processed_rows' => 10,
        'successful_rows' => 8,
        'failed_rows' => 2,
        'status' => IngestStatus::PROCESSING,
    ]);

    $service = new ConcurrencyService();
    $service->incrementCounters($run, 5, 4, 1);

    $run->refresh();

    expect($run->processed_rows)->toBe(15);
    expect($run->successful_rows)->toBe(12);
    expect($run->failed_rows)->toBe(3);
});

it('handles increment with non-existent run', function () {
    $fakeRun = new IngestRun(['id' => 999999]);

    $service = new ConcurrencyService();
    $service->incrementCounters($fakeRun, 5, 4, 1);

    expect(true)->toBeTrue();
});

it('safely finalizes processing run', function () {
    $run = IngestRun::factory()->create([
        'status' => IngestStatus::PROCESSING,
        'processed_rows' => 100,
        'successful_rows' => 95,
        'failed_rows' => 5,
    ]);

    $service = new ConcurrencyService();
    $service->safeFinalize($run);

    $run->refresh();

    expect($run->status->value)->toBeIn([
        IngestStatus::COMPLETED->value,
        IngestStatus::COMPLETED_WITH_ERRORS->value,
    ]);
});

it('handles finalization with non-existent run', function () {
    $fakeRun = new IngestRun(['id' => 999999]);

    $service = new ConcurrencyService();
    $service->safeFinalize($fakeRun);

    expect(true)->toBeTrue();
});

it('skips finalization for already finalized runs', function () {
    $run = IngestRun::factory()->create(['status' => IngestStatus::COMPLETED]);

    $service = new ConcurrencyService();
    $service->safeFinalize($run);

    $run->refresh();
    expect($run->status->value)->toBe(IngestStatus::COMPLETED->value);
});

it('updates status with locking', function () {
    $run = IngestRun::factory()->create(['status' => IngestStatus::PROCESSING]);

    $service = new ConcurrencyService();
    $result = $service->updateStatus($run, IngestStatus::FAILED);

    expect($result)->toBeTrue();

    $run->refresh();
    expect($run->status->value)->toBe(IngestStatus::FAILED->value);
});

it('returns false when updating non-existent run', function () {
    $fakeRun = new IngestRun(['id' => 999999]);

    $service = new ConcurrencyService();
    $result = $service->updateStatus($fakeRun, IngestStatus::FAILED);

    expect($result)->toBeFalse();
});

it('creates retry run', function () {
    $originalRun = IngestRun::factory()->create([
        'importer' => 'test',
        'failed_rows' => 5,
        'status' => IngestStatus::COMPLETED_WITH_ERRORS,
    ]);

    IngestRow::factory()->count(5)->create([
        'ingest_run_id' => $originalRun->id,
        'status' => 'failed',
    ]);

    $service = new ConcurrencyService();
    $retryRun = $service->createRetryRun($originalRun, 1);

    expect($retryRun)->not->toBeNull();
    expect($retryRun->parent_id)->toBe($originalRun->id);
    expect($retryRun->retried_from_run_id)->toBe($originalRun->id);
    expect($retryRun->status->value)->toBe(IngestStatus::PROCESSING->value);
});

it('returns null when creating retry for run without failed rows', function () {
    $originalRun = IngestRun::factory()->create(['failed_rows' => 0]);

    $service = new ConcurrencyService();
    $retryRun = $service->createRetryRun($originalRun);

    expect($retryRun)->toBeNull();
});

it('throws exception when retry already exists', function () {
    $originalRun = IngestRun::factory()->create([
        'importer' => 'test',
        'failed_rows' => 5,
        'status' => IngestStatus::COMPLETED_WITH_ERRORS,
    ]);

    IngestRun::factory()->create([
        'retried_from_run_id' => $originalRun->id,
        'status' => IngestStatus::PROCESSING,
    ]);

    $service = new ConcurrencyService();

    expect(fn() => $service->createRetryRun($originalRun))
        ->toThrow(ConcurrencyException::class);
});
