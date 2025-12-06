<?php

declare(strict_types=1);

namespace LaravelIngest\Console;

use Illuminate\Console\Command;
use LaravelIngest\Exceptions\NoFailedRowsException;
use LaravelIngest\IngestManager;
use LaravelIngest\Models\IngestRun;
use Throwable;

class RetryIngestCommand extends Command
{
    protected $signature = 'ingest:retry {ingestRun : The ID of the ingest run to retry failed rows for}
                            {--dry-run : Simulate the import without persisting data}';
    protected $description = 'Create a new ingest run with only the failed rows from a previous run.';

    public function handle(IngestManager $ingestManager): int
    {
        $runId = $this->argument('ingestRun');
        $isDryRun = $this->option('dry-run');

        $originalRun = IngestRun::find($runId);
        if (!$originalRun) {
            $this->error("No ingest run found with ID {$runId}.");

            return self::FAILURE;
        }

        try {
            $this->components->info("Initializing retry process for run #{$originalRun->id}...");

            if ($isDryRun) {
                $this->components->warn('Running in DRY-RUN mode. No changes will be saved to the database.');
            }

            $newRun = $ingestManager->retry($originalRun, null, $isDryRun);

            $this->components->success('Retry run successfully queued.');

            $this->table(
                ['New Run ID', 'Status', 'Total Rows'],
                [[
                    $newRun->id,
                    $newRun->status->value,
                    number_format($newRun->total_rows ?? 0),
                ]]
            );
            $this->components->twoColumnDetail('Monitor Progress', "php artisan ingest:status {$newRun->id}");

            return self::SUCCESS;

        } catch (NoFailedRowsException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('The retry process could not be started.');
            $this->components->bulletList([$e->getMessage()]);

            return self::FAILURE;
        }
    }
}
