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

        $this->path = is_string($payload) && !empty($payload)
            ? $payload
            : ($config->sourceOptions['path'] ?? null);

        if (!$this->path) {
            throw new SourceException(
                'The filesystem source is missing the "path" option. ' .
                'Please ensure you pass ["path" => "/path/to/file.csv"] when defining ->fromSource() or provide it via command argument.'
            );
        }

        $normalizedPath = str_replace('\\', '/', $this->path);
        if (str_contains($normalizedPath, '../')) {
            throw new SourceException('Invalid file path detected for security reasons.');
        }

        $realPath = realpath($this->path);
        $diskRoot = Storage::disk($disk)->path('');
        $allowedRoots = array_filter([realpath($diskRoot), realpath(base_path())]);

        if ($realPath !== false && !empty($allowedRoots)) {
            $isAllowed = false;
            foreach ($allowedRoots as $root) {
                if (str_starts_with($realPath, $root)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                throw new SourceException('Invalid file path detected for security reasons.');
            }
        }

        if ($realPath !== false) {
            $this->path = $realPath;
        }

        if (!Storage::disk($disk)->exists($this->path)) {
            throw new SourceException(
                sprintf(
                    "We could not find the file at '%s' using the disk '%s'. " .
                    'Please check the path and ensure the disk is correctly configured in filesystems.php.',
                    $this->path,
                    $disk
                )
            );
        }

        $fullPath = Storage::disk($disk)->path($this->path);

        $reader = SimpleExcelReader::create($fullPath);
        $rows = $reader->getRows();

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
}
