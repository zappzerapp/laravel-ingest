<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Extractors;

use Flow\ETL\Adapter\JSON\JsonExtractor;
use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UrlExtractor extends FlowExtractor
{
    private string $url;
    private ?string $tempFile;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function __destruct()
    {
        // Ensure temp file is cleaned up
        if ($this->tempFile !== null && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function extract(): Generator
    {
        // Download remote content to temp file
        $response = Http::get($this->url);

        if (!$response->successful()) {
            throw new RuntimeException("Failed to fetch URL: {$this->url}");
        }

        $this->tempFile = tempnam(sys_get_temp_dir(), 'flow_url_');
        file_put_contents($this->tempFile, $response->body());

        try {
            // Determine format from content-type or extension
            $contentType = $response->header('Content-Type');
            $extension = pathinfo(parse_url($this->url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

            if (str_contains($contentType, 'csv') || $extension === 'csv') {
                $extractor = new \Flow\ETL\Adapter\CSV\CSVExtractor($this->tempFile);
            } elseif (str_contains($contentType, 'json') || $extension === 'json') {
                $extractor = new JsonExtractor($this->tempFile);
            } else {
                // Default to JSON
                $extractor = new JsonExtractor($this->tempFile);
            }

            yield from $extractor->extract();
        } finally {
            // Cleanup temp file
            if ($this->tempFile !== null && file_exists($this->tempFile)) {
                unlink($this->tempFile);
            }
        }
    }
}
