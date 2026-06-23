<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;

class ErrorAnalysisService
{
    public function analyze(IngestRun $ingestRun): array
    {
        $errorCounts = [];
        $validationErrorCounts = [];
        $totalFailedRows = 0;

        foreach ($ingestRun->rows()->where('status', 'failed')->cursor() as $row) {
            /** @var IngestRow $row */
            $totalFailedRows++;
            $this->aggregateRowErrors($row, $errorCounts, $validationErrorCounts);
        }

        arsort($errorCounts);
        arsort($validationErrorCounts);

        return [
            'total_failed_rows' => $totalFailedRows,
            'error_summary' => $errorCounts,
            'validation_summary' => $validationErrorCounts,
        ];
    }

    private function aggregateRowErrors(IngestRow $row, array &$errorCounts, array &$validationErrorCounts): void
    {
        $errors = $row->errors;
        if (!is_array($errors)) {
            return;
        }

        $message = $errors['message'] ?? 'Unknown Error';
        $errorCounts[$message] = ($errorCounts[$message] ?? 0) + 1;

        if (isset($errors['validation']) && is_array($errors['validation'])) {
            $this->aggregateValidationErrors($errors['validation'], $validationErrorCounts);
        }
    }

    private function aggregateValidationErrors(array $validationErrors, array &$validationErrorCounts): void
    {
        foreach ($validationErrors as $field => $fieldErrors) {
            foreach ($fieldErrors as $fieldError) {
                $key = "{$field}: {$fieldError}";
                $validationErrorCounts[$key] = ($validationErrorCounts[$key] ?? 0) + 1;
            }
        }
    }
}
