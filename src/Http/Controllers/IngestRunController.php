<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LaravelIngest\Http\Resources\IngestRunResource;
use LaravelIngest\Models\IngestRun;

class IngestRunController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizeAccess();

        $runs = IngestRun::latest()->paginate();

        return IngestRunResource::collection($runs)->response();
    }

    public function show(IngestRun $ingestRun): JsonResponse
    {
        $this->authorizeAccess();

        $rowsLimit = config('ingest.max_show_rows', 100);
        $ingestRun->load(['rows' => fn($query) => $query->limit($rowsLimit)]);

        return IngestRunResource::make($ingestRun)->response()->setStatusCode(200);
    }
}
