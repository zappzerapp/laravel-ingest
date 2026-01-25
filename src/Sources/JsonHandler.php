<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use JsonException;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;

class JsonHandler implements SourceHandler
{
    protected ?string $processedFilePath = null;
    protected ?string $tempFilePath = null;

    /**
     * @throws SourceException
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        if (is_string($payload)) {
            $this->processedFilePath = $payload;
            $content = @file_get_contents($payload);

            if ($content === false) {
                $error = error_get_last();
                $message = $error['message'] ?? 'Unable to read JSON file.';
                throw new SourceException("Unable to read JSON file from path: {$payload}. {$message}");
            }

            try {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new SourceException('Invalid JSON: ' . $e->getMessage(), 0, $e);
            }

            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                yield $row;
            }

            return;
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
