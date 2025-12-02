<?php

namespace LaravelIngest\Sources;

use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Spatie\SimpleExcel\SimpleExcelReader;

class UploadHandler implements SourceHandler
{
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

        $disk = $config->disk ?? config('ingest.disk');
        $this->path = $payload->store('ingest-uploads', $disk);
        $fullPath = Storage::disk($disk)->path($this->path);

        $reader = SimpleExcelReader::create($fullPath);
        $rows = $reader->getRows();
        $this->totalRows = $rows->count();

        yield from $rows;
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