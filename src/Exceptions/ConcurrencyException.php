<?php

declare(strict_types=1);

namespace LaravelIngest\Exceptions;

use Exception;
use Throwable;

class ConcurrencyException extends Exception
{
    public static function duplicateRetryAttempt(int $originalRunId): self
    {
        return new self("A retry attempt for run {$originalRunId} is already in progress or completed.");
    }

    public static function lockTimeout(int $runId, int $timeout): self
    {
        return new self("Could not acquire lock for run {$runId} within {$timeout} seconds.");
    }

    public static function conflictingUpdate(int $runId, ?Throwable $previous = null): self
    {
        return new self("Conflicting update detected for run {$runId}", 0, $previous);
    }
}
