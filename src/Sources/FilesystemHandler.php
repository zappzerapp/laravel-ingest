<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Concerns\ProcessesSource;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Spatie\SimpleExcel\SimpleExcelReader;

class FilesystemHandler implements SourceHandler
{
    use ProcessesSource;

    protected ?int $totalRows = null;
    protected ?string $path = null;

    /**
     * @throws SourceException
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        $disk = $config->sourceOptions['disk'] ?? $config->disk;
        $this->path = $this->resolvePath($config, $payload);
        $this->assertPathIsSafe($this->path, $disk);
        $this->assertFileExistsOnDisk($this->path, $disk);

        $fullPath = Storage::disk($disk)->path($this->path);
        $rows = SimpleExcelReader::create($fullPath)->getRows();

        yield from $this->processRows($rows, $config);
    }

    public function getTotalRows(): ?int
    {
        return $this->totalRows;
    }

    public function getProcessedFilePath(): ?string
    {
        return $this->path;
    }

    public function cleanup(): void {}

    private function resolvePath(IngestConfig $config, mixed $payload): string
    {
        $path = is_string($payload) && !empty($payload)
            ? $payload
            : ($config->sourceOptions['path'] ?? null);

        if (!$path) {
            throw new SourceException(
                'The filesystem source is missing the "path" option. ' .
                'Please ensure you pass ["path" => "/path/to/file.csv"] when defining ->fromSource() or provide it via command argument.'
            );
        }

        $realPath = realpath($path);

        return $realPath !== false ? $realPath : $path;
    }

    private function assertPathIsSafe(string $path, string $disk): void
    {
        $normalizedPath = str_replace('\\', '/', $path);
        if (str_contains($normalizedPath, '../')) {
            throw new SourceException('Invalid file path detected for security reasons.');
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            return;
        }

        $diskRoot = Storage::disk($disk)->path('');
        $allowedRoots = array_filter([realpath($diskRoot), realpath(base_path())]);

        foreach ($allowedRoots as $root) {
            if (str_starts_with($realPath, $root)) {
                return;
            }
        }

        throw new SourceException('Invalid file path detected for security reasons.');
    }

    private function assertFileExistsOnDisk(string $path, string $disk): void
    {
        if (Storage::disk($disk)->exists($path)) {
            return;
        }

        throw new SourceException(
            sprintf(
                "We could not find the file at '%s' using the disk '%s'. " .
                'Please check the path and ensure the disk is correctly configured in filesystems.php.',
                $path,
                $disk
            )
        );
    }
}
