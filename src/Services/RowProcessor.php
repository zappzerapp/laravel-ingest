<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;
use LaravelIngest\DTOs\RowData;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Events\RowProcessed;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\ValueObjects\Timestamp;
use RuntimeException;
use Throwable;

class RowProcessor
{
    private DataTransformationService $transformationService;

    public function __construct(?DataTransformationService $transformationService = null)
    {
        $this->transformationService = $transformationService ?? new DataTransformationService();
    }

    /**
     * @throws Throwable
     */
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

    /**
     * @throws Throwable
     */
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

    /**
     * @throws Throwable
     * @throws InvalidConfigurationException
     * @throws PhpVersionNotSupportedException
     */
    private function processRow(
        IngestConfig $config,
        RowData $rowData,
        bool $isDryRun,
        array &$relationCache,
        array &$manyRelationCache
    ): ?Model {
        /**
         * @throws InvalidConfigurationException
         * @throws PhpVersionNotSupportedException
         */
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

    /**
     * @throws PhpVersionNotSupportedException
     */
    private function executeBeforeRowCallback(IngestConfig $config, RowData $rowData): void
    {
        if ($config->beforeRowCallback) {
            call_user_func_array($config->beforeRowCallback->getClosure(), [&$rowData->processedData]);
        }
    }

    /**
     * @throws PhpVersionNotSupportedException
     */
    private function executeAfterRowCallback(IngestConfig $config, ?Model $model, RowData $rowData): void
    {
        if ($config->afterRowCallback && $model) {
            call_user_func($config->afterRowCallback->getClosure(), $model, $rowData->originalData);
        }
    }

    /**
     * @throws Throwable
     * @throws JsonException
     */
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

    /**
     * @throws PhpVersionNotSupportedException
     */
    private function transform(IngestConfig $config, array $processedData, array &$relationCache, string $modelClass, bool $isDryRun = false): array
    {
        $modelData = $this->transformationService->processMappings($processedData, $config->mappings);

        $relationData = $this->transformationService->processRelations(
            $processedData,
            $config->relations,
            $relationCache,
            $modelClass,
            $isDryRun
        );

        $modelData = array_merge($modelData, $relationData);

        $unmappedData = $this->transformationService->processUnmappedData(
            $processedData,
            $config->mappings,
            $config->relations,
            $config->manyRelations,
            $this->getUsedTopLevelKeys($config),
            $modelClass
        );

        return array_merge($modelData, $unmappedData);

    }

    private function syncManyRelations(Model $model, array $originalData, IngestConfig $config, array $manyRelationCache): void
    {
        if (empty($config->manyRelations)) {
            return;
        }

        foreach ($config->manyRelations as $sourceField => $relationConfig) {
            if (!RelationService::hasNestedKey($originalData, $sourceField)) {
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

        $sources = array_merge(
            array_keys($config->mappings),
            array_keys($config->relations),
            array_keys($config->manyRelations)
        );

        foreach ($sources as $sourceField) {
            if (str_contains($sourceField, '.')) {
                $topLevelKey = explode('.', $sourceField, 2)[0];
                $topLevelKeys[$topLevelKey] = true;
            }
        }

        return $topLevelKeys;
    }

    private function persist(array $modelData, IngestConfig $config, string $modelClass): ?Model
    {
        $existingModel = $this->findExistingModel($modelData, $config, $modelClass);

        if ($existingModel) {
            return $this->handleDuplicateStrategy($existingModel, $modelData, $config);
        }

        return $modelClass::create($modelData);
    }

    private function handleDuplicateStrategy(Model $existingModel, array $modelData, IngestConfig $config): Model
    {
        return match ($config->duplicateStrategy) {
            DuplicateStrategy::UPDATE => $this->updateModel($existingModel, $modelData),
            DuplicateStrategy::SKIP => $existingModel,
            DuplicateStrategy::UPDATE_IF_NEWER => $this->updateIfNewer($existingModel, $modelData, $config),
            DuplicateStrategy::FAIL => $this->handleFailStrategy($config),
        };
    }

    private function updateModel(Model $model, array $modelData): Model
    {
        $model->update($modelData);

        return $model->fresh();
    }

    private function updateIfNewer(Model $model, array $modelData, IngestConfig $config): Model
    {
        if ($this->shouldUpdate($model, $modelData, $config)) {
            $model->update($modelData);

            return $model->fresh();
        }

        return $model;
    }

    private function handleFailStrategy(IngestConfig $config): never
    {
        throw new RuntimeException("Duplicate entry found for key '{$config->keyedBy}'.");
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

        $source = new Timestamp($sourceTimestamp);
        $db = new Timestamp($dbTimestamp);

        return $source->isNewerThan($db);
    }

    private function findExistingModel(array $modelData, IngestConfig $config, string $modelClass): ?Model
    {
        $modelKey = $config->getAttributeForKeyedBy();

        if (is_null($modelKey) || !isset($modelData[$modelKey])) {
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

    /**
     * @throws JsonException
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
