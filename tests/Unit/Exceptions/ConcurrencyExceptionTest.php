<?php

declare(strict_types=1);

use LaravelIngest\Exceptions\ConcurrencyException;

it('can create a duplicate retry attempt exception', function () {
    $exception = ConcurrencyException::duplicateRetryAttempt(123);

    expect($exception)
        ->toBeInstanceOf(ConcurrencyException::class)
        ->getMessage()->toBe('A retry attempt for run 123 is already in progress or completed.')
        ->and($exception->getCode())->toBe(0);

});

it('can create a lock timeout exception', function () {
    $exception = ConcurrencyException::lockTimeout(456, 30);

    expect($exception)
        ->toBeInstanceOf(ConcurrencyException::class)
        ->getMessage()->toBe('Could not acquire lock for run 456 within 30 seconds.')
        ->and($exception->getCode())->toBe(0);

});

it('can create a conflicting update exception', function () {
    $previous = new RuntimeException('Previous error');
    $exception = ConcurrencyException::conflictingUpdate(789, $previous);

    expect($exception)
        ->toBeInstanceOf(ConcurrencyException::class)
        ->getMessage()->toBe('Conflicting update detected for run 789')
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->getCode())->toBe(0);

});

it('can create a conflicting update exception without previous', function () {
    $exception = ConcurrencyException::conflictingUpdate(789);

    expect($exception)
        ->toBeInstanceOf(ConcurrencyException::class)
        ->getMessage()->toBe('Conflicting update detected for run 789')
        ->and($exception->getPrevious())->toBeNull();

});
