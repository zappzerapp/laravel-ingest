<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow;

use Flow\ETL\DataFrame;
use Flow\ETL\Memory\ArrayMemory;
use InvalidArgumentException;
use RuntimeException;

use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\from_array;
use function Flow\ETL\DSL\to_memory;

class FlowComponentTestCase
{
    public function createDataFrame(array $data): DataFrame
    {
        return data_frame()->read(from_array($data));
    }

    public function runPipeline(DataFrame $df): array
    {
        $memory = new ArrayMemory();

        $df->write(to_memory($memory))->run();

        return $memory->dump();
    }

    public function createDataFrameFromFixture(string $filename): DataFrame
    {
        $path = __DIR__ . '/../../fixtures/' . $filename;

        if (!file_exists($path)) {
            throw new InvalidArgumentException("Fixture file not found: {$filename}");
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return match ($extension) {
            'csv' => $this->createDataFrameFromCsv($path),
            'json' => $this->createDataFrameFromJson($path),
            default => throw new InvalidArgumentException("Unsupported fixture type: {$extension}"),
        };
    }

    private function createDataFrameFromCsv(string $path): DataFrame
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$path}");
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new RuntimeException("Cannot read CSV headers from: {$path}");
        }

        $data = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $this->createDataFrame($data);
    }

    private function createDataFrameFromJson(string $path): DataFrame
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read JSON file: {$path}");
        }

        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in file: {$path} - " . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new RuntimeException("JSON file must contain an array or object: {$path}");
        }

        if (!isset($data[0])) {
            $data = [$data];
        }

        return $this->createDataFrame($data);
    }
}
