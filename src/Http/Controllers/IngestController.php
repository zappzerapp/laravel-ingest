<?php

namespace LaravelIngest\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
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

        return IngestRunResource::make($run)
            ->response()
            ->setStatusCode(202);
    }

    public function trigger(string $importerSlug): JsonResponse
    {
        $run = $this->ingestManager->start($importerSlug, null, request()->user());

        return IngestRunResource::make($run)
            ->response()
            ->setStatusCode(202);
    }
}