<?php

namespace LaravelIngest\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\DTOs\RowData;
use LaravelIngest\Enums\DuplicateStrategy;
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

        $processLogic = function () use ($ingestRun, $config, $chunk, $isDryRun, $relationCache) {
            $results = ['processed' => 0, 'successful' => 0, 'failed' => 0];
            $rowsToLog = [];

            foreach ($chunk as $rowItem) {
                $rowData = new RowData($rowItem['data'], $rowItem['number']);
                $results['processed']++;

                try {
                    if ($config->beforeRowCallback) {
                        call_user_func_array($config->beforeRowCallback->getClosure(), [&$rowData->processedData]);
                    }

                    $this->validate($rowData->processedData, $config);

                    $transformedData = $this->transform($config, $rowData->processedData, $relationCache);

                    $model = null;
                    if (!$isDryRun) {
                        $model = $this->persist($transformedData, $config);
                    }

                } catch (Throwable $e) {
                    if ($config->useTransaction && !$isDryRun) {
                        throw $e;
                    }

                    $errors = $this->formatErrors($e);
                    $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'failed', $errors);
                    $results['failed']++;
                    RowProcessed::dispatch($ingestRun, 'failed', $rowData->originalData, null, $errors);
                    continue;
                }

                if ($config->afterRowCallback && $model) {
                    call_user_func($config->afterRowCallback->getClosure(), $model, $rowData->originalData);
                }

                $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'success');
                $results['successful']++;
                RowProcessed::dispatch($ingestRun, 'success', $rowData->originalData, $model);
            }

            if (!empty($rowsToLog) && config('ingest.log_rows')) {
                IngestRow::insert($rowsToLog);
            }

            return $results;
        };

        if ($config->useTransaction && !$isDryRun) {
            return DB::transaction($processLogic);
        }

        return $processLogic();
    }

    private function prefetchRelations(array $chunk, IngestConfig $config): array
    {
        $cache = [];
        foreach ($config->relations as $sourceField => $relationConfig) {
            $values = collect($chunk)->pluck('data.' . $sourceField)->filter()->unique()->values();
            if ($values->isEmpty()) {
                continue;
            }

            $relatedModelClass = $relationConfig['model'];
            $relatedInstance = new $relatedModelClass;
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
            $rules = array_merge($rules, $config->model::getRules());
        }

        if (!empty($rules)) {
            Validator::make($data, $rules)->validate();
        }
    }

    private function transform(IngestConfig $config, array $processedData, array $relationCache): array
    {
        $modelData = [];

        foreach ($config->mappings as $sourceField => $mapping) {
            if (!array_key_exists($sourceField, $processedData)) {
                continue;
            }

            $value = $processedData[$sourceField];
            if ($mapping['transformer'] instanceof SerializableClosure) {
                $value = call_user_func($mapping['transformer']->getClosure(), $value, $processedData);
            }
            $modelData[$mapping['attribute']] = $value;
        }

        foreach ($config->relations as $sourceField => $relationConfig) {
            if (!array_key_exists($sourceField, $processedData)) {
                continue;
            }

            $modelInstance = new $config->model;
            $relationValue = $processedData[$sourceField];
            $relatedId = null;

            if (!empty($relationValue) && isset($relationCache[$sourceField])) {
                $relatedId = $relationCache[$sourceField][$relationValue] ?? null;
            }

            $relationObject = $modelInstance->{$relationConfig['relation']}();
            $foreignKey = $relationObject->getForeignKeyName();
            $modelData[$foreignKey] = $relatedId;
        }

        $unmappedData = array_diff_key($processedData, $config->mappings, $config->relations);
        $modelInstance = new $config->model;
        foreach ($unmappedData as $key => $value) {
            if ($modelInstance->isFillable($key)) {
                $modelData[$key] = $value;
            }
        }

        return $modelData;
    }


    private function persist(array $modelData, IngestConfig $config): ?Model
    {
        $existingModel = $this->findExistingModel($modelData, $config);

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

        return $config->model::create($modelData);
    }

    private function findExistingModel(array $modelData, IngestConfig $config): ?Model
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
        return $config->model::where($modelKey, $modelData[$modelKey])->first();
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

    private function formatErrors(Throwable $e): array
    {
        $errors = ['message' => $e->getMessage()];
        if ($e instanceof ValidationException) {
            $errors['validation'] = $e->errors();
        }

        return $errors;
    }
}