<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Laravel\SerializableClosure\SerializableClosure;

class DataTransformationService
{
    public function processMappings(array $processedData, array $mappings): array
    {
        $modelData = [];

        foreach ($mappings as $sourceField => $mapping) {
            if (!RelationService::hasNestedKey($processedData, $sourceField)) {
                continue;
            }

            $value = data_get($processedData, $sourceField);

            if (($transformer = $mapping['transformer'] ?? null) && $transformer instanceof SerializableClosure) {
                $value = call_user_func($transformer->getClosure(), $value, $processedData);
            }

            $modelData[$mapping['attribute']] = $value;
        }

        return $modelData;
    }

    public function processRelations(
        array $processedData,
        array $relations,
        $relationCache,
        string $modelClass
    ): array {
        $modelData = [];
        $modelInstance = new $modelClass();

        foreach ($relations as $sourceField => $relationConfig) {
            if (!RelationService::hasNestedKey($processedData, $sourceField)) {
                continue;
            }

            $relationValue = data_get($processedData, $sourceField);
            $relatedId = null;

            if (!empty($relationValue)) {
                $relatedId = $relationCache[$sourceField][$relationValue] ?? null;

                if ($relatedId === null && ($relationConfig['createIfMissing'] ?? false)) {
                    $relatedId = RelationService::createMissingRelation($relationConfig, $relationValue, $relationCache, $sourceField);
                }
            }

            $relationObject = $modelInstance->{$relationConfig['relation']}();
            $foreignKey = $relationObject->getForeignKeyName();
            $modelData[$foreignKey] = $relatedId;
        }

        return $modelData;
    }

    public function processUnmappedData(
        array $processedData,
        array $mappings,
        array $relations,
        array $manyRelations,
        array $usedTopLevelKeys,
        string $modelClass
    ): array {
        $unmappedData = array_diff_key($processedData, $mappings, $relations, $manyRelations, $usedTopLevelKeys);
        $modelInstance = app($modelClass);

        return array_filter($unmappedData, static fn($key) => $modelInstance->isFillable($key), ARRAY_FILTER_USE_KEY);
    }
}
