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
        $url = $this->getUrlFromConfig($config);

        $this->validateUrl($url);

        $this->temporaryPath = $this->downloadToTemp($url);

        $fullPath = Storage::disk(config('ingest.disk', 'local'))->path($this->temporaryPath);
        $rows = SimpleExcelReader::create($fullPath)->getRows();

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
            Storage::disk(config('ingest.disk', 'local'))->delete($this->temporaryPath);
        }
    }

    /**
     * @return resource|false
     */
    protected function openStream(string $path)
    {
        return fopen($path, 'wb');
    }

    /**
     * @throws SourceException
     */
    private function getUrlFromConfig(IngestConfig $config): string
    {
        $url = $config->sourceOptions['url'] ?? null;
        if (!$url) {
            throw new SourceException('URL source requires a "url" option.');
        }

        return $url;
    }

    /**
     * @throws SourceException
     */
    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? null;
        $host = $parsed['host'] ?? null;

        if (!$scheme || !$host || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new SourceException('URL source requires a valid http or https URL.');
        }

        $this->validateHostAllowed($host);
        $this->validateHostBlocked($host);
        $this->validateIpSecurity($host);
    }

    /**
     * @throws SourceException
     */
    private function validateHostAllowed(string $host): void
    {
        $allowed = config('ingest_security.allowed_url_hosts');
        if (is_array($allowed) && !in_array($host, $allowed, true)) {
            throw new SourceException('URL host is not allowed.');
        }
    }

    /**
     * @throws SourceException
     */
    private function validateHostBlocked(string $host): void
    {
        $blocked = config('ingest_security.blocked_url_hosts', []);
        if (in_array($host, $blocked, true)) {
            throw new SourceException('URL host is blocked.');
        }
    }

    /**
     * @throws SourceException
     */
    private function validateIpSecurity(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($isPublic === false) {
                throw new SourceException('Private or reserved IPs are not allowed for URL sources.');
            }
        }
    }

    /**
     * @throws SourceException
     */
    private function downloadToTemp(string $url): string
    {
        $localDisk = config('ingest.disk', 'local');
        $parsedPath = parse_url($url, PHP_URL_PATH) ?? '';
        $filename = basename((string) $parsedPath) ?: 'download';

        $relativePath = 'ingest-temp/' . Str::random(40) . '/' . $filename;

        Storage::disk($localDisk)->makeDirectory(dirname($relativePath));
        $absolutePath = Storage::disk($localDisk)->path($relativePath);

        $this->performDownload($url, $absolutePath);

        return $relativePath;
    }

    /**
     * @throws SourceException
     */
    private function performDownload(string $url, string $targetPath): void
    {
        $stream = null;

        try {
            $stream = $this->openStream($targetPath);

            if ($stream === false) {
                throw new SourceException('Failed to open local stream for URL download.');
            }

            $response = Http::timeout((int) config('ingest_security.url_timeout_seconds', 15))
                ->withOptions([
                    'sink' => $stream,
                    'allow_redirects' => ['max' => (int) config('ingest_security.url_max_redirects', 5)],
                ])
                ->get($url);

            if ($response->failed()) {
                throw new SourceException("Failed to download file from URL: {$url}. Status: {$response->status()}");
            }
        } catch (Throwable $e) {
            throw new SourceException("Failed to stream file from URL {$url}: {$e->getMessage()}", 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
