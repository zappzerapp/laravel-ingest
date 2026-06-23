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

        $this->displayRun($run);

        return self::SUCCESS;
    }

    private function displayRun(IngestRun $run): void
    {
        $this->displayRunHeader($run);
        $this->displayProgressTable($run);
        $this->displayProcessingProgress($run);
        $this->displayFailureReason($run);
    }

    private function displayRunHeader(IngestRun $run): void
    {
        $this->components->info("Details for Ingest Run #{$run->id}");

        foreach ($this->getRunDetailRows($run) as [$label, $value]) {
            $this->components->twoColumnDetail($label, $value);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function getRunDetailRows(IngestRun $run): array
    {
        $statusColor = $this->getStatusColor($run->status);

        return [
            ['Importer', $run->importer],
            ['Status', "<fg={$statusColor}>{$run->status->value}</>"],
            ['User', (string) ($run->user_id ?? 'N/A')],
            ['Original File', $run->original_filename ?? 'N/A'],
            ['Started At', $run->created_at->toDateTimeString()],
            ['Completed At', $run->completed_at?->toDateTimeString() ?? 'N/A'],
        ];
    }

    private function displayProgressTable(IngestRun $run): void
    {
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
    }

    private function displayProcessingProgress(IngestRun $run): void
    {
        if ($run->status !== IngestStatus::PROCESSING || $run->total_rows <= 0) {
            return;
        }

        $this->output->createProgressBar($run->total_rows)->setProgress($run->processed_rows);
        $this->newLine(2);
    }

    private function displayFailureReason(IngestRun $run): void
    {
        if ($run->status !== IngestStatus::FAILED || empty($run->summary['error'])) {
            return;
        }

        $this->newLine();
        $this->error('Failure Reason:');
        $this->warn($run->summary['error']);
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
