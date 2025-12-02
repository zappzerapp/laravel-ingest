<?php

namespace LaravelIngest\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngestRowResource extends JsonResource
{
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