<?php

declare(strict_types=1);

namespace LaravelIngest\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneIngestFilesCommand extends Command
{
    protected $signature = 'ingest:prune-files {--hours=24 : The number of hours to retain files}';
    protected $description = 'Cleanup temporary ingest files older than a specific time.';

    public function handle(): int
    {
        $diskName = config('ingest.disk', 'local');
        $disk = Storage::disk($diskName);
        $hours = (int) $this->option('hours');
        $timestamp = now()->subHours($hours)->getTimestamp();

        $directories = ['ingest-temp', 'ingest-uploads'];
        $deletedCount = 0;

        foreach ($directories as $directory) {
            if (!$disk->exists($directory)) {
                continue;
            }

            $files = $disk->allFiles($directory);

            foreach ($files as $file) {
                $lastModified = $disk->lastModified($file);

                if ($lastModified < $timestamp) {
                    $disk->delete($file);
                    $deletedCount++;

                    $dir = dirname($file);
                    if (empty($disk->files($dir)) && empty($disk->directories($dir)) && $dir !== $directory) {
                        $disk->deleteDirectory($dir);
                    }
                }
            }
        }

        $this->info("Deleted {$deletedCount} old ingest files from disk '{$diskName}'.");

        return self::SUCCESS;
    }
}
