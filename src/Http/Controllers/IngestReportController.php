<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LaravelIngest\Http\Resources\IngestErrorSummaryResource;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\ErrorAnalysisService;
use LaravelIngest\Services\FailedRowsExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IngestReportController extends Controller
{
    public function __construct(
        protected ErrorAnalysisService $analysisService,
        protected FailedRowsExportService $exportService
    ) {}

    public function summary(IngestRun $ingestRun): JsonResponse
    {
        $this->authorizeAccess();

        $summary = $this->analysisService->analyze($ingestRun);

        return IngestErrorSummaryResource::make($summary)->response();
    }

    public function download(IngestRun $ingestRun): StreamedResponse
    {
        $this->authorizeAccess();

        return $this->exportService->exportFailedRows($ingestRun);
    }
}
