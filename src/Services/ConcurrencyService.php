<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Illuminate\Support\Facades\DB;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Exceptions\ConcurrencyException;
use LaravelIngest\Models\IngestRun;
use Throwable;

class ConcurrencyService
{
    /**
     * @throws Throwable
     */
    public function incrementCounters(IngestRun $ingestRun, int $processed, int $successful, int $failed): void
    {
        DB::transaction(static function () use ($ingestRun, $processed, $successful, $failed) {
            $lockedRun = IngestRun::where('id', $ingestRun->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedRun) {
                return;
            }

            $lockedRun->increment('processed_rows', $processed);
            $lockedRun->increment('successful_rows', $successful);
            $lockedRun->increment('failed_rows', $failed);
        });
    }

    /**
     * @throws Throwable
     */
    public function safeFinalize(IngestRun $ingestRun): void
    {
        DB::transaction(static function () use ($ingestRun) {
            $lockedRun = IngestRun::where('id', $ingestRun->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedRun) {
                return;
            }

            if (in_array($lockedRun->status->value, [
                IngestStatus::COMPLETED->value,
                IngestStatus::COMPLETED_WITH_ERRORS->value,
                IngestStatus::FAILED->value,
            ], true)) {
                return;
            }

            $lockedRun->finalize();
        });
    }

    /**
     * @throws Throwable
     */
    public function updateStatus(IngestRun $ingestRun, IngestStatus $status): bool
    {
        return DB::transaction(static function () use ($ingestRun, $status) {
            $lockedRun = IngestRun::where('id', $ingestRun->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedRun) {
                return false;
            }

            return $lockedRun->update(['status' => $status]);
        });
    }

    /**
     * @throws Throwable
     */
    public function createRetryRun(IngestRun $originalRun, ?int $userId = null): ?IngestRun
    {
        return DB::transaction(static function () use ($originalRun, $userId) {
            $lockedOriginal = IngestRun::where('id', $originalRun->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedOriginal || $lockedOriginal->failed_rows === 0) {
                return null;
            }

            $existingRetry = IngestRun::where('retried_from_run_id', $lockedOriginal->id)
                ->where('status', '!=', IngestStatus::FAILED->value)
                ->first();

            if ($existingRetry) {
                throw ConcurrencyException::duplicateRetryAttempt($lockedOriginal->id);
            }

            return IngestRun::create([
                'parent_id' => $lockedOriginal->id,
                'retried_from_run_id' => $lockedOriginal->id,
                'importer' => $lockedOriginal->importer,
                'user_id' => $userId,
                'status' => IngestStatus::PROCESSING,
                'original_filename' => $lockedOriginal->original_filename,
                'total_rows' => $lockedOriginal->failed_rows,
            ]);
        });
    }
}
