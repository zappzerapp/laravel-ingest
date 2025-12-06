<?php

namespace LaravelIngest\Console;

use Illuminate\Console\Command;
use LaravelIngest\Models\IngestRun;

class CancelIngestCommand extends Command
{
    protected $signature = 'ingest:cancel {ingestRun : The ID of the ingest run to cancel}';
    protected $description = 'Cancel a running ingest process.';

    public function handle(): int
    {
        $runId = $this->argument('ingestRun');
        $run = IngestRun::find($runId);

        if (!$run) {
            $this->error("No ingest run found with ID {$runId}.");
            return self::FAILURE;
        }

        $batch = $run->batch();
        if (!$batch) {
            $this->warn("Could not find a batch associated with run ID {$runId}. It might be already finished or failed before starting.");
            return self::FAILURE;
        }

        if ($batch->finished()) {
            $this->info("Ingest run #{$runId} has already finished.");
            return self::SUCCESS;
        }

        $batch->cancel();
        $this->info("Cancellation request sent for ingest run #{$runId}.");
        return self::SUCCESS;
    }
}