<?php

namespace LaravelIngest\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelIngest\Models\IngestRun;
use Throwable;

class IngestRunFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public IngestRun $ingestRun, public ?Throwable $exception = null)
    {
    }
}