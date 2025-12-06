<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelIngest\Models\IngestRow;

/** @mixin IngestRow */
class IngestRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'row_number' => $this->row_number,
            'status' => $this->status,
            'data' => $this->data,
            'errors' => $this->errors,
        ];
    }
}
