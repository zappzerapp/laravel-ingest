<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\DTOs\RowData;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Events\RowProcessed;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use Throwable;

class RowProcessor
{
    public function processChunk(IngestRun $ingestRun, IngestConfig $config, array $chunk, bool $isDryRun): array
    {
        $relationCache = $this->prefetchRelations($chunk, $config);
        $manyRelationCache = $this->prefetchManyRelations($chunk, $config);

        $processLogic = fn() => $this->processChunkLogic(
            $ingestRun,
            $config,
            $chunk,
            $isDryRun,
            $relationCache,
            $manyRelationCache
        );

        return $config->transactionMode === TransactionMode::CHUNK && !$isDryRun
            ? DB::transaction($processLogic)
            : $processLogic();
    }

    private function processChunkLogic(
        IngestRun $ingestRun,
        IngestConfig $config,
        array $chunk,
        bool $isDryRun,
        array $relationCache,
        array $manyRelationCache
    ): array {
        $results = ['processed' => 0, 'successful' => 0, 'failed' => 0];
        $rowsToLog = [];

        foreach ($chunk as $rowItem) {
            $rowData = new RowData($rowItem['data'], $rowItem['number']);
            $results['processed']++;

            try {
                $model = $this->processRow($config, $rowData, $isDryRun, $relationCache, $manyRelationCache);

                $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'success');
                $results['successful']++;
                RowProcessed::dispatch($ingestRun, 'success', $rowData->originalData, $model);
            } catch (Throwable $e) {
                $this->handleRowFailure($config, $e, $ingestRun, $rowData, $rowsToLog, $results, $isDryRun);
            }
        }

        $this->logRowsIfEnabled($rowsToLog);

        return $results;
    }

    private function processRow(
        IngestConfig $config,
        RowData $rowData,
        bool $isDryRun,
        array &$relationCache,
        array &$manyRelationCache
    ): ?Model {
        $rowLogic = function () use ($config, $rowData, $isDryRun, &$relationCache, &$manyRelationCache) {
            $this->executeBeforeRowCallback($config, $rowData);
            $this->validate($rowData->processedData, $config);

            $modelClass = $config->resolveModelClass($rowData->processedData);
            $transformedData = $this->transform($config, $rowData->processedData, $relationCache, $modelClass, $isDryRun);

            $model = null;
            if (!$isDryRun) {
                $model = $this->persist($transformedData, $config, $modelClass);
                $this->syncManyRelations($model, $rowData->originalData, $config, $manyRelationCache);
                $this->executeAfterRowCallback($config, $model, $rowData);
            }

            return $model;
        };

        return $config->transactionMode === TransactionMode::ROW && !$isDryRun
            ? DB::transaction($rowLogic)
            : $rowLogic();
    }

    private function executeBeforeRowCallback(IngestConfig $config, RowData $rowData): void
    {
        if ($config->beforeRowCallback) {
            call_user_func_array($config->beforeRowCallback->getClosure(), [&$rowData->processedData]);
        }
    }

    private function executeAfterRowCallback(IngestConfig $config, ?Model $model, RowData $rowData): void
    {
        if ($config->afterRowCallback && $model) {
            call_user_func($config->afterRowCallback->getClosure(), $model, $rowData->originalData);
        }
    }

    private function handleRowFailure(
        IngestConfig $config,
        Throwable $e,
        IngestRun $ingestRun,
        RowData $rowData,
        array &$rowsToLog,
        array &$results,
        bool $isDryRun
    ): void {
        if ($config->transactionMode === TransactionMode::CHUNK && !$isDryRun) {
            throw $e;
        }

        $errors = $this->formatErrors($e);
        $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'failed', $errors);
        $results['failed']++;
        RowProcessed::dispatch($ingestRun, 'failed', $rowData->originalData, null, $errors);
    }

    private function logRowsIfEnabled(array $rowsToLog): void
    {
        if (!empty($rowsToLog) && config('ingest.log_rows')) {
            IngestRow::toBase()->insert($rowsToLog);
        }
    }

    private function prefetchRelations(array $chunk, IngestConfig $config): array
    {
        $cache = [];
        foreach ($config->relations as $sourceField => $relationConfig) {
            $values = collect($chunk)
                ->map(fn($item) => data_get($item, 'data.' . $sourceField))
                ->filter()
                ->unique()
                ->values();

            if ($values->isEmpty()) {
                continue;
            }

            $relatedModelClass = $relationConfig['model'];
            $relatedInstance = app($relatedModelClass);
            $pkName = $relatedInstance->getKeyName();
            $lookupKey = $relationConfig['key'];

            $results = $relatedModelClass::query()->whereIn($lookupKey, $values)->get([$pkName, $lookupKey]);
            $cache[$sourceField] = $results->pluck($pkName, $lookupKey)->toArray();
        }

        return $cache;
    }

    private function prefetchManyRelations(array $chunk, IngestConfig $config): array
    {
        $cache = [];
        foreach ($config->manyRelations as $sourceField => $relationConfig) {
            $rawValues = collect($chunk)
                ->map(fn($item) => data_get($item, 'data.' . $sourceField))
                ->filter()
                ->values();

            if ($rawValues->isEmpty()) {
                continue;
            }

            $separator = $relationConfig['separator'];
            $lookupKey = $relationConfig['key'];
            $relatedModelClass = $relationConfig['model'];
            $relatedInstance = app($relatedModelClass);
            $pkName = $relatedInstance->getKeyName();

            $allValues = $rawValues->flatMap(fn($value) => explode($separator, $value))->filter()->unique()->values();

            if ($allValues->isEmpty()) {
                continue;
            }

            $results = $relatedModelClass::query()->whereIn($lookupKey, $allValues)->get([$pkName, $lookupKey]);
            $cache[$sourceField] = $results->pluck($pkName, $lookupKey)->toArray();
        }

        return $cache;
    }

    private function validate(array $data, IngestConfig $config): void
    {
        $rules = $config->validationRules;

        if ($config->useModelRules && method_exists($config->model, 'getRules')) {
            $rules = array_merge($config->model::getRules(), $rules);
        }

        if (!empty($rules)) {
            Validator::make($data, $rules)->validate();
        }
    }

    private function transform(IngestConfig $config, array $processedData, array &$relationCache, string $modelClass, bool $isDryRun = false): array
    {
        $modelData = [];

        foreach ($config->mappings as $sourceField => $mapping) {
            if (!$this->hasNestedKey($processedData, $sourceField)) {
                continue;
            }

            $value = data_get($processedData, $sourceField);
            if ($mapping['transformer'] instanceof SerializableClosure) {
                $value = call_user_func($mapping['transformer']->getClosure(), $value, $processedData);
            }
            $modelData[$mapping['attribute']] = $value;
        }

        foreach ($config->relations as $sourceField => $relationConfig) {
            if (!$this->hasNestedKey($processedData, $sourceField)) {
                continue;
            }

            $modelInstance = app($modelClass);
            $relationValue = data_get($processedData, $sourceField);
            $relatedId = null;

            if (!empty($relationValue)) {
                $relatedId = $relationCache[$sourceField][$relationValue] ?? null;

                if ($relatedId === null && ($relationConfig['createIfMissing'] ?? false) && !$isDryRun) {
                    $relatedId = $this->createMissingRelation($relationConfig, $relationValue, $relationCache, $sourceField);
                }
            }

            $relationObject = $modelInstance->{$relationConfig['relation']}();
            $foreignKey = $relationObject->getForeignKeyName();
            $modelData[$foreignKey] = $relatedId;
        }

        $usedTopLevelKeys = $this->getUsedTopLevelKeys($config);

        $unmappedData = array_diff_key($processedData, $config->mappings, $config->relations, $config->manyRelations, $usedTopLevelKeys);
        $modelInstance = app($modelClass);
        foreach ($unmappedData as $key => $value) {
            if ($modelInstance->isFillable($key)) {
                $modelData[$key] = $value;
            }
        }

        return $modelData;
    }

    private function hasNestedKey(array $data, string $key): bool
    {
        if (!str_contains($key, '.')) {
            return array_key_exists($key, $data);
        }

        $segments = explode('.', $key);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    private function createMissingRelation(array $relationConfig, mixed $relationValue, array &$relationCache, string $sourceField): mixed
    {
        $relatedModelClass = $relationConfig['model'];
        $lookupKey = $relationConfig['key'];

        $newRelatedModel = $relatedModelClass::create([
            $lookupKey => $relationValue,
        ]);

        if (!isset($relationCache[$sourceField])) {
            $relationCache[$sourceField] = [];
        }
        $relationCache[$sourceField][$relationValue] = $newRelatedModel->getKey();

        return $newRelatedModel->getKey();
    }

    private function syncManyRelations(Model $model, array $originalData, IngestConfig $config, array $manyRelationCache): void
    {
        if (empty($config->manyRelations)) {
            return;
        }

        foreach ($config->manyRelations as $sourceField => $relationConfig) {
            if (!$this->hasNestedKey($originalData, $sourceField)) {
                continue;
            }

            $relationValue = data_get($originalData, $sourceField);
            if (empty($relationValue)) {
                continue;
            }

            $separator = $relationConfig['separator'];
            $values = array_filter(array_map('trim', explode($separator, (string) $relationValue)));

            if (empty($values)) {
                continue;
            }

            $ids = [];
            $lookupKey = $relationConfig['key'];
            $cache = $manyRelationCache[$sourceField] ?? [];

            foreach ($values as $value) {
                $id = $cache[$value] ?? null;
                if ($id !== null) {
                    $ids[] = $id;
                }
            }

            if (!empty($ids)) {
                $model->{$relationConfig['relation']}()->syncWithoutDetaching($ids);
            }
        }
    }

    private function getUsedTopLevelKeys(IngestConfig $config): array
    {
        $topLevelKeys = [];

        foreach (array_keys($config->mappings) as $sourceField) {
            if (str_contains($sourceField, '.')) {
                $topLevelKey = explode('.', $sourceField)[0];
                $topLevelKeys[$topLevelKey] = true;
            }
        }

        foreach (array_keys($config->relations) as $sourceField) {
            if (str_contains($sourceField, '.')) {
                $topLevelKey = explode('.', $sourceField)[0];
                $topLevelKeys[$topLevelKey] = true;
            }
        }

        foreach (array_keys($config->manyRelations) as $sourceField) {
            if (str_contains($sourceField, '.')) {
                $topLevelKey = explode('.', $sourceField)[0];
                $topLevelKeys[$topLevelKey] = true;
            }
        }

        return $topLevelKeys;
    }

    private function persist(array $modelData, IngestConfig $config, string $modelClass): ?Model
    {
        $existingModel = $this->findExistingModel($modelData, $config, $modelClass);

        if ($existingModel) {
            switch ($config->duplicateStrategy) {
                case DuplicateStrategy::UPDATE:
                    $existingModel->update($modelData);

                    return $existingModel->fresh();
                case DuplicateStrategy::SKIP:
                    return $existingModel;
                case DuplicateStrategy::UPDATE_IF_NEWER:
                    if ($this->shouldUpdate($existingModel, $modelData, $config)) {
                        $existingModel->update($modelData);

                        return $existingModel->fresh();
                    }

                    return $existingModel;
                case DuplicateStrategy::FAIL:
                    throw new Exception("Duplicate entry found for key '{$config->keyedBy}'.");
            }
        }

        return $modelClass::create($modelData);
    }

    private function shouldUpdate(Model $existingModel, array $newData, IngestConfig $config): bool
    {
        if ($config->timestampComparison === null) {
            return false;
        }

        $sourceColumn = $config->timestampComparison['source_column'];
        $dbColumn = $config->timestampComparison['db_column'];

        if (!isset($newData[$sourceColumn])) {
            return false;
        }

        $dbTimestamp = $existingModel->{$dbColumn};
        $sourceTimestamp = $newData[$sourceColumn];

        if ($dbTimestamp === null) {
            return true;
        }

        if ($sourceTimestamp instanceof DateTimeInterface && $dbTimestamp instanceof DateTimeInterface) {
            return $sourceTimestamp > $dbTimestamp;
        }

        $sourceTime = $sourceTimestamp instanceof DateTimeInterface
            ? $sourceTimestamp->getTimestamp()
            : strtotime($sourceTimestamp);

        $dbTime = $dbTimestamp instanceof DateTimeInterface
            ? $dbTimestamp->getTimestamp()
            : strtotime($dbTimestamp);

        return $sourceTime > $dbTime;
    }

    private function findExistingModel(array $modelData, IngestConfig $config, string $modelClass): ?Model
    {
        $modelKey = null;
        foreach ($config->mappings as $source => $map) {
            if ($source === $config->keyedBy) {
                $modelKey = $map['attribute'];
                break;
            }
        }
        if (is_null($config->keyedBy) || is_null($modelKey) || !isset($modelData[$modelKey])) {
            return null;
        }

        return $modelClass::where($modelKey, $modelData[$modelKey])->first();
    }

    private function formatErrors(Throwable $e): array
    {
        $errors = ['message' => $e->getMessage()];
        if ($e instanceof ValidationException) {
            $errors['validation'] = $e->errors();
        }

        return $errors;
    }

    private function prepareLogRow(IngestRun $ingestRun, RowData $rowData, string $status, ?array $errors = null): array
    {
        return [
            'ingest_run_id' => $ingestRun->id,
            'row_number' => $rowData->rowNumber,
            'status' => $status,
            'data' => json_encode($rowData->originalData, JSON_THROW_ON_ERROR),
            'errors' => $errors ? json_encode($errors, JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
