<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Contracts\TransformerInterface;
use LaravelIngest\Contracts\ValidatorInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\NestedIngestConfig;

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
            $value = $this->transformMappingValue($value, $mapping, $processedData, $config, $sourceField);
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
                if (!$validator instanceof ValidatorInterface) {
                    continue;
                }

                $result = $validator->validate($value, $processedData);
                if ($result->failed()) {
                    $errors[$validatorConfig['attribute']] = array_merge(
                        $errors[$validatorConfig['attribute']] ?? [],
                        $result->errors()
                    );
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
            $value = $this->resolveConditionalMappingValue($mapping, $processedData, $config);
            if ($value === null) {
                continue;
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
            if (!isset($processedData[$sourceField]) || !is_array($processedData[$sourceField])) {
                continue;
            }

            $nestedData[$sourceField] = $this->processNestedItems($processedData[$sourceField], $nestedConfig);
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

        foreach ($relations as $sourceField => $relationConfig) {
            $foreignKeyData = $this->resolveRelationForeignKey(
                $processedData,
                $sourceField,
                $relationConfig,
                [
                    'cache' => &$relationCache,
                    'modelClass' => $modelClass,
                    'isDryRun' => $isDryRun,
                ]
            );

            if ($foreignKeyData !== null) {
                $modelData = array_merge($modelData, $foreignKeyData);
            }
        }

        return $modelData;
    }

    public function processUnmappedData(
        array $processedData,
        array $excludedSourceKeys,
        string $modelClass
    ): array {
        $unmappedData = array_diff_key($processedData, $excludedSourceKeys);

        return array_filter(
            $unmappedData,
            fn(string $key) => $this->isFillableUnmappedKey(app($modelClass), $key),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function getTraceLog(): array
    {
        return $this->traceLog;
    }

    public function clearTraceLog(): void
    {
        $this->traceLog = [];
    }

    private function transformMappingValue(
        mixed $value,
        array $mapping,
        array $processedData,
        ?IngestConfig $config,
        string $sourceField
    ): mixed {
        $traceSteps = $this->startTraceSteps($value, $config);

        foreach ($this->resolveTransformers($mapping) as $index => $transformer) {
            $value = $this->applyTransformer($transformer, $value, $processedData);
            $this->appendTraceStep($traceSteps, $config, $transformer, $value, $index);
        }

        $this->storeTraceLog($sourceField, $traceSteps, $config);

        return $value;
    }

    private function startTraceSteps(mixed $value, ?IngestConfig $config): array
    {
        if ($config === null || !$config->traceTransformations) {
            return [];
        }

        return [['step' => 'input', 'value' => $value]];
    }

    private function appendTraceStep(
        array &$traceSteps,
        ?IngestConfig $config,
        mixed $transformer,
        mixed $value,
        int $index
    ): void {
        if ($config === null || !$config->traceTransformations) {
            return;
        }

        $traceSteps[] = [
            'step' => $this->traceStepName($transformer, $index),
            'value' => $value,
        ];
    }

    private function storeTraceLog(string $sourceField, array $traceSteps, ?IngestConfig $config): void
    {
        if ($config !== null && $config->traceTransformations && count($traceSteps) > 1) {
            $this->traceLog[$sourceField] = $traceSteps;
        }
    }

    private function traceStepName(mixed $transformer, int $index): string
    {
        if ($transformer instanceof TransformerInterface) {
            return get_class($transformer);
        }

        return 'closure_' . ($index + 1);
    }

    private function resolveTransformers(array $mapping): array
    {
        if (!empty($mapping['transformers'])) {
            return $mapping['transformers'];
        }

        if (($mapping['transformer'] ?? null) !== null) {
            return [$mapping['transformer']];
        }

        return [];
    }

    private function applyTransformer(mixed $transformer, mixed $value, array $context): mixed
    {
        if ($transformer instanceof SerializableClosure) {
            return call_user_func($transformer->getClosure(), $value, $context);
        }

        if ($transformer instanceof TransformerInterface) {
            return $transformer->transform($value, $context);
        }

        if ($transformer instanceof Closure) {
            return $transformer($value, $context);
        }

        return $value;
    }

    private function resolveConditionalMappingValue(
        array $mapping,
        array $processedData,
        IngestConfig $config
    ): mixed {
        if (!$this->shouldResolveConditionalMapping($mapping, $processedData, $config)) {
            return null;
        }

        $value = data_get($processedData, $mapping['sourceField']);

        if ($mapping['transformer'] ?? null) {
            $value = $this->applyTransformer($mapping['transformer'], $value, $processedData);
        }

        return $this->passesConditionalValidator($mapping, $value, $processedData) ? $value : null;
    }

    private function shouldResolveConditionalMapping(
        array $mapping,
        array $processedData,
        IngestConfig $config
    ): bool {
        if (!$config->shouldApplyConditional($mapping, $processedData)) {
            return false;
        }

        return RelationService::hasNestedKey($processedData, $mapping['sourceField']);
    }

    private function resolveRelationForeignKey(
        array $processedData,
        string $sourceField,
        array $relationConfig,
        array $context
    ): ?array {
        if (!RelationService::hasNestedKey($processedData, $sourceField)) {
            return null;
        }

        $relatedId = $this->resolveRelatedId(
            data_get($processedData, $sourceField),
            $sourceField,
            $relationConfig,
            $context['cache'],
            $context['isDryRun']
        );

        $relationObject = app($context['modelClass'])->{$relationConfig['relation']}();

        return [$relationObject->getForeignKeyName() => $relatedId];
    }

    private function resolveRelatedId(
        mixed $relationValue,
        string $sourceField,
        array $relationConfig,
        array &$relationCache,
        bool $isDryRun
    ): mixed {
        if (empty($relationValue)) {
            return null;
        }

        $relatedId = $relationCache[$sourceField][$relationValue] ?? null;

        if ($relatedId !== null || !($relationConfig['createIfMissing'] ?? false) || $isDryRun) {
            return $relatedId;
        }

        return RelationService::createMissingRelation($relationConfig, $relationValue, $relationCache, $sourceField);
    }

    private function passesConditionalValidator(array $mapping, mixed $value, array $processedData): bool
    {
        $validator = $mapping['validator'] ?? null;
        if (!$validator instanceof ValidatorInterface) {
            return true;
        }

        return !$validator->validate($value, $processedData)->failed();
    }

    private function processNestedItems(array $nestedItems, NestedIngestConfig $nestedConfig): array
    {
        $processedNested = [];

        foreach ($nestedItems as $item) {
            $itemData = $this->processNestedItem($item, $nestedConfig);
            if (!empty($itemData)) {
                $processedNested[] = $itemData;
            }
        }

        return $processedNested;
    }

    private function processNestedItem(array $item, NestedIngestConfig $nestedConfig): array
    {
        $itemData = [];

        foreach ($nestedConfig->getMappings() as $field => $mapping) {
            if ($field === '_keyedBy' || !isset($item[$field])) {
                continue;
            }

            $value = $item[$field];
            $transformer = $nestedConfig->getTransformers()[$field] ?? null;

            if ($transformer !== null) {
                $value = $this->applyTransformer($transformer, $value, $item);
            }

            $itemData[$mapping['attribute']] = $value;
        }

        return $itemData;
    }

    private function isFillableUnmappedKey(Model $modelInstance, string $key): bool
    {
        if (!$modelInstance->isFillable($key)) {
            return false;
        }

        if (!empty($modelInstance->getGuarded()) && $modelInstance->getGuarded() !== ['*']) {
            return true;
        }

        try {
            return in_array($key, Schema::getColumnListing($modelInstance->getTable()), true);
        } catch (Exception) {
            return true;
        }
    }
}
