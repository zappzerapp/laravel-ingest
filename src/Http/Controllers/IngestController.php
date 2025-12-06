<?php

namespace LaravelIngest\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelIngest\Exceptions\NoFailedRowsException;
use LaravelIngest\Http\Requests\UploadRequest;
use LaravelIngest\Http\Resources\IngestRunResource;
use LaravelIngest\IngestManager;
use LaravelIngest\Models\IngestRun;

class IngestController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

    public function __construct(protected IngestManager $ingestManager)
    {
    }

    public function index(): JsonResponse
    {
        $runs = IngestRun::latest()->paginate();
        return IngestRunResource::collection($runs)->response();
    }

    public function show(IngestRun $ingestRun): JsonResponse
    {
        return IngestRunResource::make($ingestRun->load('rows'))->response();
    }

    public function upload(UploadRequest $request, string $importerSlug): JsonResponse
    {
        $isDryRun = $request->boolean('dry_run');
        $run = $this->ingestManager->start(
            $importerSlug,
            $request->file('file'),
            $request->user(),
            $isDryRun
        );

        return IngestRunResource::make($run)->response()->setStatusCode(202);
    }

    public function trigger(string $importerSlug): JsonResponse
    {
        $run = $this->ingestManager->start($importerSlug, null, request()->user());
        return IngestRunResource::make($run)->response()->setStatusCode(202);
    }

    public function cancel(IngestRun $ingestRun): JsonResponse
    {
        $batch = $ingestRun->batch();
        if ($batch && !$batch->finished()) {
            $batch->cancel();
        }

        return response()->json(['message' => 'Cancellation request sent.']);
    }

    public function retry(Request $request, IngestRun $ingestRun): JsonResponse
    {
        try {
            $isDryRun = $request->boolean('dry_run');
            $newRun = $this->ingestManager->retry($ingestRun, $request->user(), $isDryRun);
            return IngestRunResource::make($newRun)->response()->setStatusCode(202);
        } catch (NoFailedRowsException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}