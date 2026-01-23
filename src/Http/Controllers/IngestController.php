<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelIngest\Exceptions\NoFailedRowsException;
use LaravelIngest\Http\Requests\UploadRequest;
use LaravelIngest\Http\Resources\IngestErrorSummaryResource;
use LaravelIngest\Http\Resources\IngestRunResource;
use LaravelIngest\IngestManager;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\ErrorAnalysisService;
use LaravelIngest\Services\FailedRowsExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IngestController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    public function __construct(
        protected IngestManager $ingestManager,
        protected FailedRowsExportService $exportService
    ) {}

    public function index(): JsonResponse
    {
        $this->authorizeAccess();

        $runs = IngestRun::latest()->paginate();

        return IngestRunResource::collection($runs)->response();
    }

    public function show(IngestRun $ingestRun): JsonResponse
    {
        $this->authorizeAccess();

        return IngestRunResource::make($ingestRun->load('rows'))->response();
    }

    public function upload(UploadRequest $request, string $importer): JsonResponse
    {
        $this->authorizeAccess();

        $isDryRun = $request->boolean('dry_run');
        $run = $this->ingestManager->start(
            $importer,
            $request->file('file'),
            $request->user(),
            $isDryRun
        );

        return IngestRunResource::make($run)->response()->setStatusCode(202);
    }

    public function trigger(string $importer): JsonResponse
    {
        $this->authorizeAccess();

        $run = $this->ingestManager->start($importer, null, request()->user());

        return IngestRunResource::make($run)->response()->setStatusCode(202);
    }

    public function cancel(IngestRun $ingestRun): JsonResponse
    {
        $this->authorizeAccess();

        $batch = $ingestRun->batch();
        if ($batch && !$batch->finished()) {
            $batch->cancel();
        }

        return response()->json(['message' => 'Cancellation request sent.']);
    }

    public function retry(Request $request, IngestRun $ingestRun): JsonResponse
    {
        $this->authorizeAccess();

        try {
            $isDryRun = $request->boolean('dry_run');
            $newRun = $this->ingestManager->retry($ingestRun, $request->user(), $isDryRun);

            return IngestRunResource::make($newRun)->response()->setStatusCode(202);
        } catch (NoFailedRowsException $e) {
            abort(400, $e->getMessage());
        }
    }

    public function errorSummary(IngestRun $ingestRun, ErrorAnalysisService $analysisService): JsonResponse
    {
        $this->authorizeAccess();

        $summary = $analysisService->analyze($ingestRun);

        return IngestErrorSummaryResource::make($summary)->response();
    }

    public function downloadFailedRows(IngestRun $ingestRun): StreamedResponse
    {
        $this->authorizeAccess();

        return $this->exportService->exportFailedRows($ingestRun);
    }

    protected function authorizeAccess(): void
    {
        $this->authorize('viewIngest');
    }
}
