<?php

declare(strict_types=1);

namespace LaravelIngest\ValueObjects;

final readonly class ImportStats
{
    /**
     * @param  int  $totalRows  Total number of rows processed
     * @param  int  $successCount  Number of successfully processed rows
     * @param  int  $failureCount  Number of failed rows
     * @param  int  $createdCount  Number of new records created
     * @param  int  $updatedCount  Number of existing records updated
     * @param  float  $duration  Total duration in seconds
     * @param  array  $errors  Array of error summaries
     */
    public function __construct(
        public int $totalRows,
        public int $successCount,
        public int $failureCount,
        public int $createdCount,
        public int $updatedCount,
        public float $duration,
        public array $errors = []
    ) {}

    public function successRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return round(($this->successCount / $this->totalRows) * 100, 2);
    }

    public function wasFullySuccessful(): bool
    {
        return $this->failureCount === 0 && $this->successCount > 0;
    }

    public function skippedCount(): int
    {
        return $this->successCount - $this->createdCount - $this->updatedCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'created_count' => $this->createdCount,
            'updated_count' => $this->updatedCount,
            'skipped_count' => $this->skippedCount(),
            'success_rate' => $this->successRate(),
            'duration' => $this->duration,
            'errors' => $this->errors,
        ];
    }
}
