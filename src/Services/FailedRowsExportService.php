<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Response;
use LaravelIngest\Models\IngestRun;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FailedRowsExportService
{
    public function exportFailedRows(IngestRun $ingestRun): StreamedResponse
    {
        $failedRows = $this->getFailedRows($ingestRun);

        if ($failedRows->isEmpty()) {
            abort(404, 'No failed rows found for this ingest run.');
        }

        $headers = $this->prepareHeaders($failedRows);
        $filename = "failed-rows-{$ingestRun->id}.csv";

        return $this->createCsvResponse($headers, $failedRows, $filename);
    }

    private function getFailedRows(IngestRun $ingestRun): Collection
    {
        return $ingestRun->rows()
            ->where('status', 'failed')
            ->orderBy('row_number')
            ->get();
    }

    private function prepareHeaders($failedRows): array
    {
        $headers = [];
        $firstRow = $failedRows->first();

        if (is_array($firstRow->data)) {
            $headers = array_keys($firstRow->data);
        }

        $headers[] = '_error_message';
        $headers[] = '_row_number';

        return $headers;
    }

    private function createCsvResponse(array $headers, $failedRows, string $filename): StreamedResponse
    {
        return Response::stream(function () use ($headers, $failedRows) {
            $output = fopen('php://output', 'wb');

            // @codeCoverageIgnoreStart
            if ($output === false) {
                return;
            }
            // @codeCoverageIgnoreEnd

            fputcsv($output, $headers);

            foreach ($failedRows as $row) {
                $outputRow = $this->prepareOutputRow($row, $headers);
                fputcsv($output, $outputRow);
            }

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function prepareOutputRow($row, array $headers): array
    {
        $data = $row->data;
        $outputRow = [];

        foreach (array_slice($headers, 0, -2) as $header) {
            $outputRow[] = $data[$header] ?? '';
        }

        $outputRow[] = is_array($row->errors) ? ($row->errors['message'] ?? '') : '';
        $outputRow[] = $row->row_number;

        return $outputRow;
    }
}
