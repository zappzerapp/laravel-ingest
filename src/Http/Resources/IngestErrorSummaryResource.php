<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngestErrorSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total_failed_rows' => $this->resource['total_failed_rows'],
            'error_summary' => $this->formatSummary($this->resource['error_summary']),
            'validation_summary' => $this->formatSummary($this->resource['validation_summary']),
        ];
    }

    /**
     * @param array<string, int> $summary
     * @return array<int, array<string, string|int>>
     */
    private function formatSummary(array $summary): array
    {
        $formatted = [];
        foreach ($summary as $message => $count) {
            $formatted[] = [
                'message' => $message,
                'count' => $count,
            ];
        }

        return $formatted;
    }
}