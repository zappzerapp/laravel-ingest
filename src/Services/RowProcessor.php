<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;
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

        /**
         * @throws Throwable
         * @throws PhpVersionNotSupportedException
         * @throws JsonException
         */
        $processLogic = function () use ($ingestRun, $config, $chunk, $isDryRun, $relationCache) {
            $results = ['processed' => 0, 'successful' => 0, 'failed' => 0];
            $rowsToLog = [];

            foreach ($chunk as $rowItem) {
                $rowData = new RowData($rowItem['data'], $rowItem['number']);
                $results['processed']++;

                try {
                    $rowLogic = function () use ($config, $rowData, $isDryRun, &$relationCache, &$model) {
                        if ($config->beforeRowCallback) {
                            call_user_func_array($config->beforeRowCallback->getClosure(), [&$rowData->processedData]);
                        }

                        $this->validate($rowData->processedData, $config);

                        $modelClass = $config->resolveModelClass($rowData->processedData);
                        $transformedData = $this->transform($config, $rowData->processedData, $relationCache, $modelClass, $isDryRun);

                        if (!$isDryRun) {
                            $model = $this->persist($transformedData, $config, $modelClass);
                        }

                        if ($config->afterRowCallback && $model) {
                            call_user_func($config->afterRowCallback->getClosure(), $model, $rowData->originalData);
                        }
                    };

                    if ($config->transactionMode === TransactionMode::ROW && !$isDryRun) {
                        DB::transaction($rowLogic);
                    } else {
                        $rowLogic();
                    }

                } catch (Throwable $e) {
                    if ($config->transactionMode === TransactionMode::CHUNK && !$isDryRun) {
                        throw $e;
                    }

                    $errors = $this->formatErrors($e);
                    $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'failed', $errors);
                    $results['failed']++;
                    RowProcessed::dispatch($ingestRun, 'failed', $rowData->originalData, null, $errors);

                    continue;
                }

                $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'success');
                $results['successful']++;
                RowProcessed::dispatch($ingestRun, 'success', $rowData->originalData, $model ?? null);
                $model = null;
            }

            if (!empty($rowsToLog) && config('ingest.log_rows')) {
                IngestRow::toBase()->insert($rowsToLog);
            }

            return $results;
        };

        if ($config->transactionMode === TransactionMode::CHUNK && !$isDryRun) {
            return DB::transaction($processLogic);
        }

        return $processLogic();
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

        $unmappedData = array_diff_key($processedData, $config->mappings, $config->relations, $usedTopLevelKeys);
        $modelInstance = app($modelClass);
        foreach ($unmappedData as $key => $value) {
            if ($modelInstance->isFillable($key)) {
                $modelData[$key] = $value;
            }
        }

        return $modelData;
    }

    /**
     * Check if a key exists in the data, supporting dot notation for nested keys.
     */
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

    /**
     * Create a missing related model and update the cache.
     */
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

    /**
     * Get top-level keys that are used by nested dot-notation paths in mappings and relations.
     *
     * This prevents nested source data (like 'user' in 'user.profile.name') from being
     * treated as unmapped fillable data.
     */
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
                case DuplicateStrategy::FAIL:
                    throw new Exception("Duplicate entry found for key '{$config->keyedBy}'.");
            }
        }

        return $modelClass::create($modelData);
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
