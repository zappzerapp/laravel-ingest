<?php

declare(strict_types=1);

namespace LaravelIngest\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelIngest\Models\IngestRun;

class ChunkProcessed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public IngestRun $ingestRun, public array $results) {}
}
