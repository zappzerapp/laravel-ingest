<?php

declare(strict_types=1);

use LaravelIngest\ValueObjects\ImportStats;

it('calculates success rate', function () {
    $stats = new ImportStats(
        totalRows: 100,
        successCount: 85,
        failureCount: 15,
        createdCount: 40,
        updatedCount: 45,
        duration: 10.5
    );

    expect($stats->successRate())->toBe(85.0);
});

it('returns zero for empty total rows', function () {
    $stats = new ImportStats(
        totalRows: 0,
        successCount: 0,
        failureCount: 0,
        createdCount: 0,
        updatedCount: 0,
        duration: 0
    );

    expect($stats->successRate())->toBe(0.0);
});

it('calculates skipped count', function () {
    $stats = new ImportStats(
        totalRows: 100,
        successCount: 90,
        failureCount: 10,
        createdCount: 30,
        updatedCount: 50,
        duration: 5.0
    );

    expect($stats->skippedCount())->toBe(10);
});

it('detects fully successful import', function () {
    $successful = new ImportStats(
        totalRows: 100,
        successCount: 100,
        failureCount: 0,
        createdCount: 100,
        updatedCount: 0,
        duration: 10.0
    );

    $failed = new ImportStats(
        totalRows: 100,
        successCount: 99,
        failureCount: 1,
        createdCount: 99,
        updatedCount: 0,
        duration: 10.0
    );

    expect($successful->wasFullySuccessful())->toBeTrue()
        ->and($failed->wasFullySuccessful())->toBeFalse();
});

it('converts to array', function () {
    $stats = new ImportStats(
        totalRows: 100,
        successCount: 90,
        failureCount: 10,
        createdCount: 50,
        updatedCount: 40,
        duration: 5.5,
        errors: ['Invalid email', 'Missing required field']
    );

    $array = $stats->toArray();

    expect($array)->toHaveKey('total_rows')
        ->and($array)->toHaveKey('success_rate')
        ->and($array['success_rate'])->toBe(90.0)
        ->and($array['skipped_count'])->toBe(0);
});

it('handles errors in toArray', function () {
    $stats = new ImportStats(
        totalRows: 100,
        successCount: 100,
        failureCount: 0,
        createdCount: 100,
        updatedCount: 0,
        duration: 1.0,
        errors: ['error1', 'error2']
    );

    $array = $stats->toArray();

    expect($array['errors'])->toHaveCount(2);
});
