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
use LaravelIngest\ValueObjects\FileSize;
use LaravelIngest\ValueObjects\MimeType;
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

        $this->validateFile($payload, $config);

        $this->path = $this->storeFile($payload, $config);

        $fullPath = Storage::disk($config->disk ?? config('ingest.disk'))->path($this->path);
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

    public function cleanup(): void
    {
        if ($this->path) {
            Storage::disk(config('ingest.disk'))->delete($this->path);
        }
    }

    /**
     * @throws SourceException
     */
    private function validateFile(UploadedFile $file, IngestConfig $config): void
    {
        $this->validateMimeType($file, $config);
        $this->validateSize($file, $config);
    }

    /**
     * @throws SourceException
     */
    private function validateMimeType(UploadedFile $file, IngestConfig $config): void
    {
        $allowedMimes = $config->sourceOptions['allowed_mimes'] ?? [
            'text/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ];

        $clientMime = new MimeType($file->getClientMimeType());
        $realMime = new MimeType(finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file->getPathname()));

        if (!$realMime->isIn($allowedMimes) && !$clientMime->isTextType()) {
            throw new SourceException(
                "File type '{$realMime->toString()}' is not allowed. Allowed types: " . implode(', ', $allowedMimes)
            );
        }
    }

    /**
     * @throws SourceException
     */
    private function validateSize(UploadedFile $file, IngestConfig $config): void
    {
        $maxBytes = $config->sourceOptions['max_size_mb'] ?? 50 * 1024 * 1024;

        $maxSize = new FileSize((int) $maxBytes);
        $fileSize = new FileSize($file->getSize());

        if ($fileSize->exceeds($maxSize)) {
            throw new SourceException('File size exceeds maximum allowed size of ' . $maxSize->toString());
        }
    }

    private function storeFile(UploadedFile $file, IngestConfig $config): string
    {
        $disk = $config->disk ?? config('ingest.disk');

        return $file->store('ingest-uploads', $disk);
    }
}
