<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Exception;
use Illuminate\Support\Facades\Schema;

class DataTransformationService
{
    public function processMappings(array $processedData, array $mappings): array
    {
        $modelData = [];

        foreach ($mappings as $sourceField => $mapping) {
            if (!data_get($processedData, $sourceField)) {
                continue;
            }

            $value = data_get($processedData, $sourceField);

            if (($transformer = $mapping['transformer'] ?? null) && $transformer instanceof \Laravel\SerializableClosure\SerializableClosure) {
                $value = call_user_func($transformer->getClosure(), $value, $processedData);
            }

            $modelData[$mapping['attribute']] = $value;
        }

        return $modelData;
    }

    public function processRelations(
        array $processedData,
        array $relations,
        array &$relationCache,
        string $modelClass,
        bool $isDryRun = false
    ): array {
        $modelData = [];
        $modelInstance = app($modelClass);

        foreach ($relations as $sourceField => $relationConfig) {
            if (!RelationService::hasNestedKey($processedData, $sourceField)) {
                continue;
            }

            $relationValue = data_get($processedData, $sourceField);
            $relatedId = null;

            if (!empty($relationValue)) {
                $relatedId = $relationCache[$sourceField][$relationValue] ?? null;

                if ($relatedId === null && ($relationConfig['createIfMissing'] ?? false) && !$isDryRun) {
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

        // Further filter to only include keys that actually exist as database columns
        // This prevents trying to insert keys that don't correspond to real columns
        return array_filter($unmappedData, static function ($key) use ($modelInstance) {
            // Only include keys that are actually fillable AND correspond to real database columns
            if (!$modelInstance->isFillable($key)) {
                return false;
            }

            // For models where all attributes are fillable (guarded=[]),
            // we need additional validation to ensure the key corresponds to a real column
            if (empty($modelInstance->getGuarded()) || $modelInstance->getGuarded() === ['*']) {
                try {
                    // Get the actual database column names
                    $tableColumns = Schema::getColumnListing($modelInstance->getTable());

                    return in_array($key, $tableColumns, true);
                } catch (Exception $e) {
                    // If we can't determine the columns, fall back to the original behavior
                    return true;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
    }
}
