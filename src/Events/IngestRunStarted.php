<?php

namespace LaravelIngest\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelIngest\Models\IngestRun;

class IngestRunStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public IngestRun $ingestRun)
    {
    }
}