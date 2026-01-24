<?php

declare(strict_types=1);

namespace LaravelIngest\Concerns;

use Generator;
use Iterator;
use IteratorAggregate;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use Traversable;

trait ProcessesSource
{
    /**
     * @throws SourceException
     */
    protected function processRows(IteratorAggregate|Traversable $rows, IngestConfig $config): Generator
    {
        /** @var Iterator<int, array<string, mixed>> $iterator */
        $iterator = $rows instanceof IteratorAggregate ? $rows->getIterator() : $rows;

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
        while (true) {
            if (!$iterator->valid()) {
                break;
            }
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

    /**
     * @throws SourceException
     */
    private function validateKeyedByHeader(IngestConfig $config, array $translationMap): void
    {
        if ($config->keyedBy && !in_array($config->keyedBy, $translationMap, true)) {
            throw new SourceException("The key column '{$config->keyedBy}' or one of its aliases was not found in the source file headers.");
        }

        if (!$config->strictHeaders) {
            return;
        }

        foreach ($config->mappings as $sourceField => $mapping) {
            $hasMatch = in_array($sourceField, $translationMap, true);

            if (!$hasMatch) {
                foreach ($mapping['aliases'] as $alias) {
                    if (in_array($alias, $translationMap, true)) {
                        $hasMatch = true;
                        break;
                    }
                }
            }

            if (!$hasMatch) {
                $aliasList = implode("', '", array_merge([$sourceField], $mapping['aliases']));
                throw new SourceException("None of the required columns ['{$aliasList}'] were found in the source file headers. Strict header validation is enabled.");
            }
        }

        foreach ($config->relations as $sourceField => $relationConfig) {
            if (!in_array($sourceField, $translationMap, true)) {
                throw new SourceException("The column '{$sourceField}' was not found in the source file headers. Strict header validation is enabled.");
            }
        }

        foreach ($config->manyRelations as $sourceField => $relationConfig) {
            if (!in_array($sourceField, $translationMap, true)) {
                throw new SourceException("The column '{$sourceField}' was not found in the source file headers. Strict header validation is enabled.");
            }
        }
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
}
