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
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use Throwable;

class RowProcessor
{
    /**
     * @throws Throwable
     */
    public function processChunk(IngestRun $ingestRun, IngestConfig $config, array $chunk, bool $isDryRun): array
    {
        $relationCache = $this->prefetchRelations($chunk, $config);

        /**
         * @throws Throwable
         */
        $processLogic = function () use ($ingestRun, $config, $chunk, $isDryRun, $relationCache) {
            $results = ['processed' => 0, 'successful' => 0, 'failed' => 0];
            $rowsToLog = [];

            foreach ($chunk as $rowItem) {
                $rowData = new RowData($rowItem['data'], $rowItem['number']);
                $results['processed']++;

                try {
                    $validatedData = $this->validate($rowData, $config);
                    $transformedData = $this->transform($rowData, $config, $validatedData, $relationCache);

                    if (!$isDryRun) {
                        $this->persist($transformedData, $config);
                    }

                    $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'success');
                    $results['successful']++;

                } catch (Throwable $e) {
                    if ($config->useTransaction && !$isDryRun) {
                        throw $e;
                    }

                    $errors = ['message' => $e->getMessage()];
                    if ($e instanceof ValidationException) {
                        $errors['validation'] = $e->errors();
                    }

                    $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'failed', $errors);
                    $results['failed']++;
                }
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
            $values = collect($chunk)
                ->pluck('data.' . $sourceField)
                ->filter()
                ->unique()
                ->values();

            if ($values->isEmpty()) {
                continue;
            }

            $relatedModelClass = $relationConfig['model'];
            $relatedInstance = new $relatedModelClass;
            $pkName = $relatedInstance->getKeyName();
            $lookupKey = $relationConfig['key'];

            $results = $relatedModelClass::query()
                ->whereIn($lookupKey, $values)
                ->get([$pkName, $lookupKey]);

            $cache[$sourceField] = $results->pluck($pkName, $lookupKey)->toArray();
        }

        return $cache;
    }

    private function validate(RowData $rowData, IngestConfig $config): array
    {
        $rules = $config->validationRules;
        if ($config->useModelRules && method_exists($config->model, 'getRules')) {
            $rules = array_merge($rules, $config->model::getRules());
        }

        if (!empty($rules)) {
            Validator::make($rowData->originalData, $rules)->validate();
        }

        return $rowData->originalData;
    }

    private function transform(RowData $rowData, IngestConfig $config, array $validatedData, array $relationCache): array
    {
        $modelData = [];
        $modelInstance = new $config->model;

        foreach ($config->mappings as $sourceField => $mapping) {
            if (!array_key_exists($sourceField, $validatedData)) continue;

            $value = $validatedData[$sourceField];

            if ($mapping['transformer'] instanceof SerializableClosure) {
                $value = call_user_func($mapping['transformer']->getClosure(), $value, $rowData->originalData);
            }
            $modelData[$mapping['attribute']] = $value;
        }

        foreach ($config->relations as $sourceField => $relationConfig) {
            if (!array_key_exists($sourceField, $validatedData)) continue;

            $relationValue = $validatedData[$sourceField];
            $relationObject = $modelInstance->{$relationConfig['relation']}();
            $foreignKey = $relationObject->getForeignKeyName();

            $relatedId = $relationCache[$sourceField][$relationValue] ?? null;

            $modelData[$foreignKey] = $relatedId;
        }

        return $modelData;
    }

    /**
     * @throws Exception
     */
    private function persist(array $modelData, IngestConfig $config): void
    {
        $model = $this->findExistingModel($modelData, $config);

        if ($model) {
            switch ($config->duplicateStrategy) {
                case DuplicateStrategy::FAIL:
                    throw new Exception("Duplicate entry found for key '{$config->keyedBy}'.");
                case DuplicateStrategy::SKIP:
                    return;
                case DuplicateStrategy::UPDATE:
                    $model->update($modelData);
                    break;
            }
        } else {
            $config->model::create($modelData);
        }
    }

    private function findExistingModel(array $modelData, IngestConfig $config): ?Model
    {
        $modelKey = null;
        foreach($config->mappings as $source => $map) {
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

    /**
     * @throws \JsonException
     */
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