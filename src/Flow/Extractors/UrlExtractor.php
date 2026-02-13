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

        $this->tempFile = tempnam(sys_get_temp_dir(), 'flow_url_');
        file_put_contents($this->tempFile, $response->body());

        try {
            $contentType = $response->header('Content-Type');
            $extension = pathinfo(parse_url($this->url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

            if (str_contains($contentType, 'csv') || $extension === 'csv') {
                $extractor = new CSVExtractor(Path::from($this->tempFile));
            } elseif (str_contains($contentType, 'json') || $extension === 'json') {
                $extractor = new JsonExtractor(Path::from($this->tempFile));
            } else {
                $extractor = new JsonExtractor(Path::from($this->tempFile));
            }

            yield from $extractor->extract($context);
        } finally {
            if ($this->tempFile !== null && file_exists($this->tempFile)) {
                unlink($this->tempFile);
            }
        }
    }
}
