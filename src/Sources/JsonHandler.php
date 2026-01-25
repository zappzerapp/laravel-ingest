<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use JsonMachine\Items;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Throwable;

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

            if (!file_exists($payload)) {
                throw new SourceException("Unable to read JSON file from path: {$payload}");
            }

            try {
                $items = Items::fromFile($payload);

                foreach ($items as $row) {
                    if (is_object($row)) {
                        $row = (array) $row;
                    }

                    if (!is_array($row)) {
                        continue;
                    }

                    yield $row;
                }
            } catch (Throwable $e) {
                throw new SourceException('Invalid JSON: ' . $e->getMessage(), 0, $e);
            }

            return;
        }

        throw new SourceException('JsonHandler expects a valid file path');
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
