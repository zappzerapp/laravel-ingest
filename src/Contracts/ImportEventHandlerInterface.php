<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use LaravelIngest\DTOs\RowData;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\ValueObjects\ImportStats;
use Throwable;

/**
 * @example
 * class SendSlackNotificationHandler implements ImportEventHandlerInterface
 * {
 *     public function beforeImport(IngestRun $run): void {}
 *
 *     public function onRowProcessed(IngestRun $run, RowData $row, object $model): void {}
 *
 *     public function onError(IngestRun $run, RowData $row, \Throwable $error): void {}
 *
 *     public function afterImport(IngestRun $run, ImportStats $stats): void {}
 * }
 */
interface ImportEventHandlerInterface
{
    public function beforeImport(IngestRun $run): void;

    public function onRowProcessed(IngestRun $run, RowData $row, object $model): void;

    public function onError(IngestRun $run, RowData $row, Throwable $error): void;

    public function afterImport(IngestRun $run, ImportStats $stats): void;
}
