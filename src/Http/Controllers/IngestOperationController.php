<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LaravelIngest\Exceptions\ConcurrencyException;
use LaravelIngest\Exceptions\DefinitionNotFoundException;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Exceptions\NoFailedRowsException;
use LaravelIngest\Http\Requests\RetryIngestRequest;
use LaravelIngest\Http\Requests\UploadRequest;
use LaravelIngest\Http\Resources\IngestRunResource;
use LaravelIngest\IngestManager;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\FailedRowsExportService;
use Throwable;

class IngestOperationController extends Controller
{
    public function __construct(
        protected IngestManager $ingestManager,
        protected FailedRowsExportService $exportService
    ) {}

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

    /**
     * @throws Throwable
     * @throws InvalidConfigurationException
     * @throws DefinitionNotFoundException
     */
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

    /**
     * @throws Throwable
     * @throws ConcurrencyException
     * @throws DefinitionNotFoundException
     */
    public function retry(RetryIngestRequest $request, IngestRun $ingestRun): JsonResponse
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
}
