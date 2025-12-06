<?php

declare(strict_types=1);

namespace LaravelIngest\Console;

use Exception;
use Illuminate\Console\Command;
use LaravelIngest\IngestManager;

class RunIngestCommand extends Command
{
    protected $signature = 'ingest:run {slug}
                            {--file= : The path to the file to ingest (for filesystem sources)}
                            {--dry-run : Simulate the import without persisting data}';
    protected $description = 'Manually trigger an ingest process.';

    public function handle(IngestManager $ingestManager): int
    {
        $slug = $this->argument('slug');
        $file = $this->option('file');
        $isDryRun = $this->option('dry-run');

        try {
            $this->components->info("Initializing ingest process for '{$slug}'...");

            if ($isDryRun) {
                $this->components->warn('Running in DRY-RUN mode. No changes will be saved to the database.');
            }

            $ingestRun = $ingestManager->start($slug, $file, null, $isDryRun);

            $this->components->success('Ingest run successfully queued.');

            $this->table(
                ['Run ID', 'Status', 'Total Rows'],
                [[
                    $ingestRun->id,
                    $ingestRun->status->value,
                    number_format($ingestRun->total_rows ?? 0),
                ]]
            );

            $this->components->twoColumnDetail('Monitor Progress', "php artisan ingest:status {$ingestRun->id}");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->components->error('The ingest process could not be started.');
            $this->components->bulletList([
                $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }
}
