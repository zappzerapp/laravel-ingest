<?php

namespace LaravelIngest\Sources;

use Generator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Spatie\SimpleExcel\SimpleExcelReader;

class FtpHandler implements SourceHandler
{
    protected ?string $temporaryPath = null;
    protected ?int $totalRows = null;

    /**
     * @throws SourceException
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        $diskName = $config->sourceOptions['disk'] ?? null;
        if (!$diskName) {
            throw new SourceException('FTP/SFTP source requires a "disk" option to be specified.');
        }

        $remotePath = $config->sourceOptions['path'] ?? null;
        if (!$remotePath) {
            throw new SourceException('FTP/SFTP source requires a "path" option.');
        }

        if (!Storage::disk($diskName)->exists($remotePath)) {
            throw new SourceException("File not found at remote path '{$remotePath}' on disk '{$diskName}'.");
        }

        $localDisk = config('ingest.disk', 'local');
        $this->temporaryPath = 'ingest-temp/' . Str::random(40) . '/' . basename($remotePath);

        $remoteStream = Storage::disk($diskName)->readStream($remotePath);
        if ($remoteStream === false) {
            throw new SourceException("Could not open read stream for remote file '{$remotePath}' on disk '{$diskName}'.");
        }
        Storage::disk($localDisk)->putStream($this->temporaryPath, $remoteStream);
        if (is_resource($remoteStream)) {
            fclose($remoteStream);
        }

        $fullPath = Storage::disk($localDisk)->path($this->temporaryPath);

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