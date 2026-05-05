<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Contracts\TransformerInterface;
use LaravelIngest\Contracts\ValidatorInterface;
use LaravelIngest\IngestConfig;

class DataTransformationService
{
    private array $traceLog = [];

    public function processMappings(
        array $processedData,
        array $mappings,
        ?IngestConfig $config = null
    ): array {
        $modelData = [];

        foreach ($mappings as $sourceField => $mapping) {
            if (!RelationService::hasNestedKey($processedData, $sourceField)) {
                continue;
            }

            $value = data_get($processedData, $sourceField);
            $originalValue = $value;
            $traceSteps = [];

            if ($config !== null && $config->traceTransformations) {
                $traceSteps[] = [
                    'step' => 'input',
                    'value' => $originalValue,
                ];
            }

            $transformers = [];

            if (!empty($mapping['transformers'])) {
                $transformers = $mapping['transformers'];
            } elseif (($mapping['transformer'] ?? null) !== null) {
                $transformers = [$mapping['transformer']];
            }

            foreach ($transformers as $index => $transformer) {
                if ($transformer instanceof SerializableClosure) {
                    $value = call_user_func($transformer->getClosure(), $value, $processedData);
                    if ($config !== null && $config->traceTransformations) {
                        $traceSteps[] = [
                            'step' => 'closure_' . ($index + 1),
                            'value' => $value,
                        ];
                    }
                } elseif ($transformer instanceof TransformerInterface) {
                    $value = $transformer->transform($value, $processedData);
                    if ($config !== null && $config->traceTransformations) {
                        $traceSteps[] = [
                            'step' => get_class($transformer),
                            'value' => $value,
                        ];
                    }
                } elseif ($transformer instanceof Closure) {
                    $value = $transformer($value, $processedData);
                    if ($config !== null && $config->traceTransformations) {
                        $traceSteps[] = [
                            'step' => 'closure_' . ($index + 1),
                            'value' => $value,
                        ];
                    }
                }
            }

            if ($config !== null && $config->traceTransformations && count($traceSteps) > 1) {
                $this->traceLog[$sourceField] = $traceSteps;
            }

            $modelData[$mapping['attribute']] = $value;
        }

        return $modelData;
    }

    public function processValidators(
        array $processedData,
        array $validators,
        IngestConfig $config
    ): array {
        $errors = [];

        foreach ($validators as $sourceField => $validatorConfig) {
            $value = $processedData[$sourceField] ?? null;

            foreach ($validatorConfig['validators'] as $validator) {
                if ($validator instanceof ValidatorInterface) {
                    $result = $validator->validate($value, $processedData);
                    if ($result->failed()) {
                        $errors[$validatorConfig['attribute']] = array_merge(
                            $errors[$validatorConfig['attribute']] ?? [],
                            $result->errors()
                        );
                    }
                }
            }
        }

        return $errors;
    }

    public function processConditionalMappings(
        array $processedData,
        array $conditionalMappings,
        IngestConfig $config
    ): array {
        $modelData = [];

        foreach ($conditionalMappings as $mapping) {
            if (!$config->shouldApplyConditional($mapping, $processedData)) {
                continue;
            }

            $sourceField = $mapping['sourceField'];
            if (!RelationService::hasNestedKey($processedData, $sourceField)) {
                continue;
            }

            $value = data_get($processedData, $sourceField);

            if ($mapping['transformer'] ?? null) {
                $transformer = $mapping['transformer'];
                if ($transformer instanceof SerializableClosure) {
                    $value = call_user_func($transformer->getClosure(), $value, $processedData);
                } elseif ($transformer instanceof TransformerInterface) {
                    $value = $transformer->transform($value, $processedData);
                }
            }

            if ($mapping['validator'] ?? null) {
                $validator = $mapping['validator'];
                if ($validator instanceof ValidatorInterface) {
                    $result = $validator->validate($value, $processedData);
                    if ($result->failed()) {
                        continue;
                    }
                }
            }

            $modelData[$mapping['attribute']] = $value;
        }

        return $modelData;
    }

    public function processNestedData(
        array $processedData,
        array $nestedConfigs
    ): array {
        $nestedData = [];

        foreach ($nestedConfigs as $sourceField => $nestedConfig) {
            if (!isset($processedData[$sourceField])) {
                continue;
            }

            $nestedItems = $processedData[$sourceField];
            if (!is_array($nestedItems)) {
                continue;
            }

            $processedNested = [];
            foreach ($nestedItems as $item) {
                $itemData = [];
                foreach ($nestedConfig->getMappings() as $field => $mapping) {
                    if ($field === '_keyedBy') {
                        continue;
                    }

                    if (isset($item[$field])) {
                        $value = $item[$field];

                        if (isset($nestedConfig->getTransformers()[$field])) {
                            $transformer = $nestedConfig->getTransformers()[$field];
                            if ($transformer instanceof SerializableClosure) {
                                $value = call_user_func($transformer->getClosure(), $value, $item);
                            } elseif ($transformer instanceof TransformerInterface) {
                                $value = $transformer->transform($value, $item);
                            }
                        }

                        $itemData[$mapping['attribute']] = $value;
                    }
                }

                if (!empty($itemData)) {
                    $processedNested[] = $itemData;
                }
            }

            $nestedData[$sourceField] = $processedNested;
        }

        return $nestedData;
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

        return array_filter($unmappedData, static fn($key) => $modelInstance->isFillable($key), ARRAY_FILTER_USE_KEY);
    }

    public function getTraceLog(): array
    {
        return $this->traceLog;
    }

    public function clearTraceLog(): void
    {
        $this->traceLog = [];
    }
}
