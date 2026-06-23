<?php

declare(strict_types=1);

namespace LaravelIngest\ValueObjects;

final readonly class ImportStats
{
    public function __construct(
        public int $totalRows,
        public ImportCounts $counts,
        public float $duration,
        public array $errors = []
    ) {}

    public function successRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return round(($this->counts->success / $this->totalRows) * 100, 2);
    }

    public function wasFullySuccessful(): bool
    {
        return $this->counts->failure === 0 && $this->counts->success > 0;
    }

    public function skippedCount(): int
    {
        return $this->counts->success - $this->counts->created - $this->counts->updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'success_count' => $this->counts->success,
            'failure_count' => $this->counts->failure,
            'created_count' => $this->counts->created,
            'updated_count' => $this->counts->updated,
            'skipped_count' => $this->skippedCount(),
            'success_rate' => $this->successRate(),
            'duration' => $this->duration,
            'errors' => $this->errors,
        ];
    }
}
