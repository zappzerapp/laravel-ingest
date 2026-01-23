<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelIngest\Concerns\ProcessesSource;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

class RemoteDiskHandler implements SourceHandler
{
    use ProcessesSource;

    protected ?string $temporaryPath = null;
    protected ?int $totalRows = null;

    /**
     * @throws SourceException
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        $this->validateConfig($config);

        try {
            $fullPath = $this->downloadRemoteFile($config);
            $this->totalRows = $this->countRows($fullPath);

            yield from $this->readAndProcessRows($fullPath, $config);
        } catch (SourceException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SourceException('Failed to read from remote source: ' . $e->getMessage(), 0, $e);
        }
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

    private function validateConfig(IngestConfig $config): void
    {
        $diskName = $config->sourceOptions['disk'] ?? null;
        if (!$diskName) {
            throw new SourceException('FTP/SFTP source requires a "disk" option to be specified.');
        }

        $remotePath = $config->sourceOptions['path'] ?? null;
        if (!$remotePath) {
            throw new SourceException('FTP/SFTP source requires a "path" option.');
        }
    }

    private function downloadRemoteFile(IngestConfig $config): string
    {
        $diskName = $config->sourceOptions['disk'];
        $remotePath = $config->sourceOptions['path'];

        if (!Storage::disk($diskName)->exists($remotePath)) {
            throw new SourceException("File not found at remote path '{$remotePath}' on disk '{$diskName}'.");
        }

        $localDisk = config('ingest.disk', 'local');
        $this->temporaryPath = 'ingest-temp/' . Str::random(40) . '/' . basename($remotePath);

        $remoteStream = Storage::disk($diskName)->readStream($remotePath);
        if ($remoteStream === null) {
            throw new SourceException("Could not open read stream for remote file '{$remotePath}' on disk '{$diskName}'.");
        }

        Storage::disk($localDisk)->put($this->temporaryPath, $remoteStream);
        if (is_resource($remoteStream)) {
            fclose($remoteStream);
        }

        return Storage::disk($localDisk)->path($this->temporaryPath);
    }

    private function countRows(string $fullPath): int
    {
        $reader = SimpleExcelReader::create($fullPath);

        return $reader->getRows()->count();
    }

    private function readAndProcessRows(string $fullPath, IngestConfig $config): Generator
    {
        $reader = SimpleExcelReader::create($fullPath);
        $rows = $reader->getRows();

        return $this->processRows($rows, $config);
    }
}
