<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use LaravelIngest\DTOs\RowData;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\ValueObjects\ImportStats;
use Throwable;

/**
 * Interface for event handlers that hook into the import lifecycle.
 *
 * Event handlers allow custom logic to run at specific points during the import
 * process, such as preprocessing data, logging progress, or triggering side effects.
 *
 * @example
 * class SendSlackNotificationHandler implements ImportEventHandlerInterface
 * {
 *     public function beforeImport(IngestRun $run): void
 *     {
 *         // Called once before processing starts
 *     }
 *
 *     public function onRowProcessed(IngestRun $run, RowData $row, object $model): void
 *     {
 *         // Called after each row is successfully processed
 *     }
 *
 *     public function onError(IngestRun $run, RowData $row, \Throwable $error): void
 *     {
 *         // Called when a row fails processing
 *     }
 *
 *     public function afterImport(IngestRun $run, ImportStats $stats): void
 *     {
 *         // Called once after all processing completes
 *     }
 * }
 */
interface ImportEventHandlerInterface
{
    public function beforeImport(IngestRun $run): void;

    /**
     * @param  IngestRun  $run  The current ingest run
     * @param  RowData  $row  The processed row data
     * @param  object  $model  The created/updated model instance
     */
    public function onRowProcessed(IngestRun $run, RowData $row, object $model): void;

    /**
     * @param  IngestRun  $run  The current ingest run
     * @param  RowData  $row  The row that failed
     * @param  Throwable  $error  The error that occurred
     */
    public function onError(IngestRun $run, RowData $row, Throwable $error): void;

    /**
     * @param  IngestRun  $run  The completed ingest run
     * @param  ImportStats  $stats  Statistics about the import
     */
    public function afterImport(IngestRun $run, ImportStats $stats): void;
}
