<?php

declare(strict_types=1);

namespace LaravelIngest\Concerns;

use Exception;
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
     * @throws Exception
     */
    protected function processRows(IteratorAggregate|Traversable $rows, IngestConfig $config): Generator
    {
        /** @var Iterator<int, array<string, mixed>> $iterator */
        $iterator = $rows instanceof IteratorAggregate ? $rows->getIterator() : $rows;

        if (!$iterator->valid()) {
            return;
        }

        $firstRow = $iterator->current();
        $translationMap = $this->buildTranslationMap(array_keys($firstRow), $config->getHeaderNormalizationMap());

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

    /**
     * @throws SourceException
     */
    private function validateKeyedByHeader(IngestConfig $config, array $translationMap): void
    {
        if ($config->keyedBy) {
            $keyedByFields = is_array($config->keyedBy) ? $config->keyedBy : [$config->keyedBy];

            foreach ($keyedByFields as $keyField) {
                if (!in_array($keyField, $translationMap, true)) {
                    throw new SourceException("The key column '{$keyField}' or one of its aliases was not found in the source file headers.");
                }
            }
        }

        if ($config->strictHeaders) {
            $this->validateStrictMappings($config, $translationMap);
            $this->validateStrictRelations($config->relations, $translationMap);
            $this->validateStrictRelations($config->manyRelations, $translationMap);
        }
    }

    private function validateStrictMappings(IngestConfig $config, array $translationMap): void
    {
        foreach ($config->mappings as $sourceField => $mapping) {
            $aliases = array_merge([$sourceField], $mapping['aliases']);

            if (!$this->hasMatchInHeaders($aliases, $translationMap)) {
                $aliasList = implode("', '", $aliases);
                throw new SourceException("None of the required columns ['{$aliasList}'] were found in the source file headers. Strict header validation is enabled.");
            }
        }
    }

    private function hasMatchInHeaders(array $candidates, array $translationMap): bool
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $translationMap, true)) {
                return true;
            }
        }

        return false;
    }

    private function validateStrictRelations(array $relations, array $translationMap): void
    {
        foreach (array_keys($relations) as $sourceField) {
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
