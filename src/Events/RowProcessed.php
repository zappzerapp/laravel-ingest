<?php

namespace LaravelIngest\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelIngest\Models\IngestRun;

class RowProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public IngestRun $ingestRun,
        public string $status,
        public array $originalData,
        public ?Model $model,
        public ?array $errors = null
    ) {
    }
}