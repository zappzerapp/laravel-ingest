<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelIngest\Models\IngestRun;

/** @mixin IngestRun */
class IngestRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'importer' => $this->importer,
            'status' => $this->status,
            'user_id' => $this->user_id,
            'original_filename' => $this->original_filename,
            'progress' => [
                'total' => $this->total_rows,
                'processed' => $this->processed_rows,
                'successful' => $this->successful_rows,
                'failed' => $this->failed_rows,
            ],
            'summary' => $this->summary,
            'started_at' => $this->created_at,
            'completed_at' => $this->completed_at,
            'rows' => IngestRowResource::collection($this->whenLoaded('rows')),
        ];
    }
}
