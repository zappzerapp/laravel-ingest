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
        if (!is_string($payload)) {
            throw new SourceException('JsonHandler expects a valid file path');
        }

        $this->processedFilePath = $payload;

        if (!file_exists($payload)) {
            throw new SourceException("Unable to read JSON file from path: {$payload}");
        }

        yield from $this->yieldJsonRows($payload);
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

    /**
     * @throws SourceException
     */
    private function yieldJsonRows(string $path): Generator
    {
        try {
            foreach (Items::fromFile($path) as $row) {
                $normalizedRow = $this->normalizeJsonRow($row);
                if ($normalizedRow !== null) {
                    yield $normalizedRow;
                }
            }
        } catch (Throwable $e) {
            throw new SourceException('Invalid JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    private function normalizeJsonRow(mixed $row): ?array
    {
        if (is_object($row)) {
            $row = (array) $row;
        }

        return is_array($row) ? $row : null;
    }
}
