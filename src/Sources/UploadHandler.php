<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Concerns\ProcessesSource;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Spatie\SimpleExcel\SimpleExcelReader;

class UploadHandler implements SourceHandler
{
    use ProcessesSource;

    protected ?string $path = null;
    protected ?int $totalRows = null;

    /**
     * @throws SourceException
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        if (!$payload instanceof UploadedFile) {
            throw new SourceException('UploadHandler expects an instance of UploadedFile.');
        }

        $allowedMimes = $config->sourceOptions['allowed_mimes'] ?? ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'];
        $maxSizeBytes = $config->sourceOptions['max_size_mb'] ?? 50 * 1024 * 1024;

        $clientMimeType = $payload->getClientMimeType();
        $finfoMimeType = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $payload->getPathname());

        if ($clientMimeType !== $finfoMimeType && !in_array($finfoMimeType, $allowedMimes, true) && !in_array($clientMimeType, ['text/plain', 'text/csv'], true)) {
            if (!in_array($finfoMimeType, $allowedMimes, true)) {
                throw new SourceException("File type '{$finfoMimeType}' is not allowed. Allowed types: " . implode(', ', $allowedMimes));
            }
        }

        if ($payload->getSize() > $maxSizeBytes) {
            throw new SourceException('File size exceeds maximum allowed size of ' . ($maxSizeBytes / 1024 / 1024) . ' MB');
        }

        $disk = $config->disk ?? config('ingest.disk');
        $this->path = $payload->store('ingest-uploads', $disk);
        $fullPath = Storage::disk($disk)->path($this->path);

        $reader = SimpleExcelReader::create($fullPath);
        $rows = $reader->getRows();
        $this->totalRows = $rows->count();

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

    public function cleanup(): void
    {
        if ($this->path) {
            $disk = config('ingest.disk');
            Storage::disk($disk)->delete($this->path);
        }
    }
}
