<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use LaravelIngest\Models\IngestRun;

class ErrorAnalysisService
{
    public function analyze(IngestRun $ingestRun): array
    {
        $errorCounts = [];
        $validationErrorCounts = [];

        $failedRows = $ingestRun->rows()->where('status', 'failed')->cursor();

        foreach ($failedRows as $row) {
            $errors = $row->errors;
            if (!is_array($errors)) {
                continue;
            }

            $message = $errors['message'] ?? 'Unknown Error';
            $errorCounts[$message] = ($errorCounts[$message] ?? 0) + 1;

            if (isset($errors['validation']) && is_array($errors['validation'])) {
                foreach ($errors['validation'] as $field => $fieldErrors) {
                    foreach ($fieldErrors as $fieldError) {
                        $key = "{$field}: {$fieldError}";
                        $validationErrorCounts[$key] = ($validationErrorCounts[$key] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($errorCounts);
        arsort($validationErrorCounts);

        return [
            'total_failed_rows' => $failedRows->count(),
            'error_summary' => $errorCounts,
            'validation_summary' => $validationErrorCounts,
        ];
    }
}
