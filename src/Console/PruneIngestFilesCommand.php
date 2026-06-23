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
        $disk = Storage::disk(config('ingest.disk', 'local'));
        $timestamp = now()->subHours((int) $this->option('hours'))->getTimestamp();
        $deletedCount = 0;

        foreach (['ingest-temp', 'ingest-uploads'] as $directory) {
            $deletedCount += $this->pruneDirectory($disk, $directory, $timestamp);
        }

        $this->info("Deleted {$deletedCount} old ingest files from disk '" . config('ingest.disk', 'local') . "'.");

        return self::SUCCESS;
    }

    private function pruneDirectory($disk, string $directory, int $timestamp): int
    {
        if (!$disk->exists($directory)) {
            return 0;
        }

        $deletedCount = 0;

        foreach ($disk->allFiles($directory) as $file) {
            if ($disk->lastModified($file) >= $timestamp) {
                continue;
            }

            $disk->delete($file);
            $deletedCount++;
            $this->cleanupEmptyDirectory($disk, dirname($file), $directory);
        }

        return $deletedCount;
    }

    private function cleanupEmptyDirectory($disk, string $dir, string $rootDirectory): void
    {
        if ($dir === $rootDirectory) {
            return;
        }

        if (empty($disk->files($dir)) && empty($disk->directories($dir))) {
            $disk->deleteDirectory($dir);
        }
    }
}
