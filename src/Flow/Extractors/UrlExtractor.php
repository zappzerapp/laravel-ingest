<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Extractors;

use Flow\ETL\Adapter\CSV\CSVExtractor;
use Flow\ETL\Adapter\JSON\JSONMachine\JsonExtractor;
use Flow\ETL\FlowContext;
use Flow\Filesystem\Path;
use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UrlExtractor extends FlowExtractor
{
    private string $url;
    private ?string $tempFile = null;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function __destruct()
    {
        if ($this->tempFile !== null && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function extract(FlowContext $context): Generator
    {
        $response = Http::get($this->url);

        if (!$response->successful()) {
            throw new RuntimeException("Failed to fetch URL: {$this->url}");
        }

        $this->tempFile = $this->createTempFile($response->body());

        try {
            $extractor = $this->resolveExtractor($response->header('Content-Type'));
            yield from $extractor->extract($context);
        } finally {
            $this->cleanupTempFile();
        }
    }

    private function createTempFile(string $body): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'flow_url_');
        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for URL extraction');
        }

        file_put_contents($tempFile, $body);

        return $tempFile;
    }

    private function resolveExtractor(?string $contentType): CSVExtractor|JsonExtractor
    {
        $extension = pathinfo(parse_url($this->url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

        if (str_contains((string) $contentType, 'csv') || $extension === 'csv') {
            return new CSVExtractor(Path::from($this->tempFile));
        }

        return new JsonExtractor(Path::from($this->tempFile));
    }

    private function cleanupTempFile(): void
    {
        if ($this->tempFile !== null && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }
}
