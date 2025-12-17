<?php

declare(strict_types=1);

namespace LaravelIngest\Console;

use Illuminate\Console\Command;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Models\IngestRun;

class StatusIngestCommand extends Command
{
    protected $signature = 'ingest:status {ingestRun : The ID of the ingest run}';
    protected $description = 'Check the status of a specific ingest run.';

    public function handle(): int
    {
        $runId = $this->argument('ingestRun');
        $run = IngestRun::find($runId);

        if (!$run) {
            $this->error("No ingest run found with ID {$runId}.");

            return self::FAILURE;
        }

        $this->components->info("Details for Ingest Run #{$run->id}");

        $this->components->twoColumnDetail('Importer', $run->importer_slug);
        $this->components->twoColumnDetail('Status', "<fg={$this->getStatusColor($run->status)}>{$run->status->value}</>");
        $this->components->twoColumnDetail('User', $run->user_id ?? 'N/A');
        $this->components->twoColumnDetail('Original File', $run->original_filename ?? 'N/A');
        $this->components->twoColumnDetail('Started At', $run->created_at->toDateTimeString());
        $this->components->twoColumnDetail('Completed At', $run->completed_at?->toDateTimeString() ?? 'N/A');

        $this->newLine();
        $this->line('Progress:');

        $this->table(
            ['Total', 'Processed', 'Successful', 'Failed'],
            [[
                number_format($run->total_rows),
                number_format($run->processed_rows),
                number_format($run->successful_rows),
                number_format($run->failed_rows),
            ]]
        );

        if ($run->status === IngestStatus::PROCESSING && $run->total_rows > 0) {
            $this->output->createProgressBar($run->total_rows)->setProgress($run->processed_rows);
            $this->newLine(2);
        }

        if ($run->status === IngestStatus::FAILED && !empty($run->summary['error'])) {
            $this->newLine();
            $this->error('Failure Reason:');
            $this->warn($run->summary['error']);
        }

        return self::SUCCESS;
    }

    private function getStatusColor(IngestStatus $status): string
    {
        return match ($status) {
            IngestStatus::PENDING, IngestStatus::PROCESSING, IngestStatus::COMPLETED_WITH_ERRORS => 'yellow',
            IngestStatus::COMPLETED => 'green',
            IngestStatus::FAILED => 'red',
        };
    }
}
