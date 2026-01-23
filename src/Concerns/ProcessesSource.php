<?php

declare(strict_types=1);

namespace LaravelIngest\Concerns;

use Generator;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;

trait ProcessesSource
{
    /**
     * @throws SourceException
     */
    protected function processRows(iterable $rows, IngestConfig $config): Generator
    {
        $iterator = $rows->getIterator();
        if (!$iterator->valid()) {
            return;
        }

        $firstRow = $iterator->current();
        $actualHeaders = array_keys($firstRow);
        $normalizationMap = $config->getHeaderNormalizationMap();

        $translationMap = $this->buildTranslationMap($actualHeaders, $normalizationMap);

        $this->validateKeyedByHeader($config, $translationMap);

        yield $this->translateRow($firstRow, $translationMap);

        $iterator->next();
        while ($iterator->valid()) {
            yield $this->translateRow($iterator->current(), $translationMap);
            $iterator->next();
        }
    }

    private function buildTranslationMap(array $actualHeaders, array $normalizationMap): array
    {
        $map = [];
        foreach ($actualHeaders as $header) {
            if (isset($normalizationMap[$header])) {
                $map[$header] = $normalizationMap[$header];
            }
        }

        return $map;
    }

    private function translateRow(array $row, array $translationMap): array
    {
        $newRow = [];
        foreach ($row as $key => $value) {
            $newKey = $translationMap[$key] ?? $key;
            $newRow[$newKey] = $value;
        }

        return $newRow;
    }

    /**
     * @throws SourceException
     */
    private function validateKeyedByHeader(IngestConfig $config, array $translationMap): void
    {
        if (!$config->keyedBy) {
            return;
        }

        $primaryKeyExists = in_array($config->keyedBy, $translationMap, true);

        if (!$primaryKeyExists) {
            throw new SourceException("The key column '{$config->keyedBy}' or one of its aliases was not found in the source file headers.");
        }
    }
}