<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;

class JsonHandler implements SourceHandler
{
    protected ?string $processedFilePath = null;
    protected ?string $tempFilePath = null;

    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        if (is_string($payload)) {
            $this->processedFilePath = $payload;
            $content = @file_get_contents($payload);

            if ($content === false) {
                throw new SourceException("Unable to read JSON file from path: {$payload}");
            }

            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new SourceException('Invalid JSON: ' . json_last_error_msg());
            }

            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                yield $row;
            }

            return $this;
        }

        throw new SourceException('JsonHandler expects a valid file path.');
    }

    public function getTotalRows(): ?int
    {
        return null;
    }

    public function getProcessedFilePath(): ?string
    {
        return $this->processedFilePath;
    }

    public function cleanup(): void
    {
        if ($this->tempFilePath !== null && file_exists($this->tempFilePath)) {
            unlink($this->tempFilePath);
            $this->tempFilePath = null;
        }
    }
}
