<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelIngest\Concerns\ProcessesSource;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

class UrlHandler implements SourceHandler
{
    use ProcessesSource;

    protected ?string $temporaryPath = null;
    protected ?int $totalRows = null;

    /**
     * @throws SourceException
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        $url = $config->sourceOptions['url'] ?? null;
        if (!$url) {
            throw new SourceException('URL source requires a "url" option.');
        }

        $localDisk = config('ingest.disk', 'local');
        $filename = basename(parse_url($url, PHP_URL_PATH));
        $this->temporaryPath = 'ingest-temp/' . Str::random(40) . '/' . $filename;

        try {
            Storage::disk($localDisk)->makeDirectory(dirname($this->temporaryPath));
            $stream = fopen(Storage::disk($localDisk)->path($this->temporaryPath), 'wb');
            $response = Http::withOptions(['sink' => $stream])->get($url);

            if ($response->failed()) {
                throw new SourceException("Failed to download file from URL: {$url}. Status: {$response->status()}");
            }
        } catch (Throwable $e) {
            throw new SourceException("Failed to stream file from URL {$url}: {$e->getMessage()}", 0, $e);
        }

        $fullPath = Storage::disk($localDisk)->path($this->temporaryPath);

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
        return $this->temporaryPath;
    }

    public function cleanup(): void
    {
        if ($this->temporaryPath) {
            $localDisk = config('ingest.disk', 'local');
            Storage::disk($localDisk)->delete($this->temporaryPath);
        }
    }
}
