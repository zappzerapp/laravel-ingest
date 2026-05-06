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
            $this->processSingleRow($rowItem, $ingestRun, $config, $isDryRun, $relationCache, $manyRelationCache, $results, $rowsToLog);
        }

        $this->logRowsIfEnabled($rowsToLog);

        return $results;
    }

    /**
     * @throws Throwable
     */
    private function processSingleRow(
        array $rowItem,
        IngestRun $ingestRun,
        IngestConfig $config,
        bool $isDryRun,
        array &$relationCache,
        array &$manyRelationCache,
        array &$results,
        array &$rowsToLog
    ): void {
        $rowData = new RowData($rowItem['data'], $rowItem['number']);
        $results['processed']++;

        try {
            $model = $this->processRow($config, $rowData, $isDryRun, $relationCache, $manyRelationCache);
            $this->handleRowSuccess($ingestRun, $rowData, $model, $results, $rowsToLog);
        } catch (Throwable $e) {
            $this->handleRowFailure($config, $e, $ingestRun, $rowData, $rowsToLog, $results, $isDryRun);
        }
    }

    private function handleRowSuccess(
        IngestRun $ingestRun,
        RowData $rowData,
        ?Model $model,
        array &$results,
        array &$rowsToLog
    ): void {
        $rowsToLog[] = $this->prepareLogRow($ingestRun, $rowData, 'success');
        $results['successful']++;
        RowProcessed::dispatch($ingestRun, 'success', $rowData->originalData, $model);
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
        $rowLogic = function () use ($config, $rowData, $isDryRun, &$relationCache, &$manyRelationCache) {
            return $this->executeRowLifecycle($config, $rowData, $isDryRun, $relationCache, $manyRelationCache);
        };

        return $config->transactionMode === TransactionMode::ROW && !$isDryRun
            ? DB::transaction($rowLogic)
            : $rowLogic();
    }

    /**
     * @throws InvalidConfigurationException
     * @throws PhpVersionNotSupportedException
     */
    private function executeRowLifecycle(
        IngestConfig $config,
        RowData $rowData,
        bool $isDryRun,
        array &$relationCache,
        array &$manyRelationCache
    ): ?Model {
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
            $this->prefetchSingleRelation($chunk, $sourceField, $relationConfig, $cache);
        }

        return $cache;
    }

    private function prefetchSingleRelation(
        array $chunk,
        string $sourceField,
        array $relationConfig,
        array &$cache
    ): void {
        $values = $this->extractUniqueFieldValues($chunk, $sourceField);

        if ($values->isEmpty()) {
            return;
        }

        $relatedModelClass = $relationConfig['model'];
        $lookupKey = $relationConfig['key'];
        $primaryKeyName = app($relatedModelClass)->getKeyName();

        $results = $relatedModelClass::query()->whereIn($lookupKey, $values)->get([$primaryKeyName, $lookupKey]);
        $cache[$sourceField] = $results->pluck($primaryKeyName, $lookupKey)->toArray();
    }

    /**
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    private function extractUniqueFieldValues(array $chunk, string $field): \Illuminate\Support\Collection
    {
        return collect($chunk)
            ->map(fn($item) => data_get($item, 'data.' . $field))
            ->filter()
            ->unique()
            ->values();
    }

    private function prefetchManyRelations(array $chunk, IngestConfig $config): array
    {
        $cache = [];
        foreach ($config->manyRelations as $sourceField => $relationConfig) {
            $this->prefetchSingleManyRelation($chunk, $sourceField, $relationConfig, $cache);
        }

        return $cache;
    }

    private function prefetchSingleManyRelation(
        array $chunk,
        string $sourceField,
        array $relationConfig,
        array &$cache
    ): void {
        $rawValues = $this->extractUniqueFieldValues($chunk, $sourceField);

        if ($rawValues->isEmpty()) {
            return;
        }

        $separator = $relationConfig['separator'];
        $allValues = $rawValues->flatMap(fn($value) => explode($separator, $value))->filter()->unique()->values();

        if ($allValues->isEmpty()) {
            return;
        }

        $lookupKey = $relationConfig['key'];
        $relatedModelClass = $relationConfig['model'];
        $primaryKeyName = app($relatedModelClass)->getKeyName();

        $results = $relatedModelClass::query()->whereIn($lookupKey, $allValues)->get([$primaryKeyName, $lookupKey]);
        $cache[$sourceField] = $results->pluck($primaryKeyName, $lookupKey)->toArray();
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

        $unmappedData = $this->transformationService->processUnmappedData(
            $processedData,
            $config->mappings,
            $config->relations,
            $config->manyRelations,
            $this->getUsedTopLevelKeys($config),
            $modelClass
        );

        return array_merge($modelData, $relationData, $unmappedData);
    }

    private function syncManyRelations(Model $model, array $originalData, IngestConfig $config, array $manyRelationCache): void
    {
        if (empty($config->manyRelations)) {
            return;
        }

        foreach ($config->manyRelations as $sourceField => $relationConfig) {
            $this->syncSingleManyRelation($model, $originalData, $sourceField, $relationConfig, $manyRelationCache);
        }
    }

    private function syncSingleManyRelation(
        Model $model,
        array $originalData,
        string $sourceField,
        array $relationConfig,
        array $manyRelationCache
    ): void {
        if (!RelationService::hasNestedKey($originalData, $sourceField)) {
            return;
        }

        $ids = $this->resolveRelationIds($originalData, $sourceField, $relationConfig, $manyRelationCache);

        if (!empty($ids)) {
            $model->{$relationConfig['relation']}()->syncWithoutDetaching($ids);
        }
    }

    /**
     * @return int[]
     */
    private function resolveRelationIds(
        array $originalData,
        string $sourceField,
        array $relationConfig,
        array $manyRelationCache
    ): array {
        $relationValue = data_get($originalData, $sourceField);
        if (empty($relationValue)) {
            return [];
        }

        $separator = $relationConfig['separator'];
        $values = array_filter(array_map('trim', explode($separator, (string) $relationValue)));

        if (empty($values)) {
            return [];
        }

        $cache = $manyRelationCache[$sourceField] ?? [];

        return array_filter(
            array_map(fn($value) => $cache[$value] ?? null, $values)
        );
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
        if ($config->duplicateStrategy === DuplicateStrategy::UPSERT) {
            return $this->upsertModel($modelData, $config, $modelClass);
        }

        $existingModel = $this->findExistingModel($modelData, $config, $modelClass);

        if ($existingModel) {
            return $this->handleDuplicateStrategy($existingModel, $modelData, $config);
        }

        return $modelClass::create($modelData);
    }

    private function handleDuplicateStrategy(Model $existingModel, array $modelData, IngestConfig $config): Model
    {
        return match ($config->duplicateStrategy) {
            DuplicateStrategy::UPDATE, DuplicateStrategy::UPSERT => $this->updateModel($existingModel, $modelData),
            DuplicateStrategy::SKIP => $existingModel,
            DuplicateStrategy::UPDATE_IF_NEWER => $this->updateIfNewer($existingModel, $modelData, $config),
            DuplicateStrategy::FAIL => $this->throwDuplicateEntryException($config),
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

    private function upsertModel(array $modelData, IngestConfig $config, string $modelClass): Model
    {
        $uniqueKeys = $config->getAttributesForKeyedBy();

        if (empty($uniqueKeys)) {
            return $modelClass::create($modelData);
        }

        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        $this->prepareUpsertTimestamps($model, $modelData);
        $updateColumns = $this->calculateUpdateColumns($model, $modelData, $uniqueKeys);

        if (empty($updateColumns)) {
            return $modelClass::create($modelData);
        }

        DB::table($table)->upsert([$modelData], $uniqueKeys, $updateColumns);

        return $this->fetchUpsertedModel($modelClass, $modelData, $uniqueKeys);
    }

    private function prepareUpsertTimestamps(Model $model, array &$modelData): void
    {
        if ($model->usesTimestamps()) {
            $now = now();
            $modelData[$model->getCreatedAtColumn()] = $now;
            $modelData[$model->getUpdatedAtColumn()] = $now;
        }
    }

    /**
     * @return string[]
     */
    private function calculateUpdateColumns(Model $model, array $modelData, array $uniqueKeys): array
    {
        $excludeFromUpdate = array_flip($uniqueKeys);
        if ($model->usesTimestamps()) {
            $excludeFromUpdate[$model->getCreatedAtColumn()] = true;
        }

        return array_keys(array_diff_key($modelData, $excludeFromUpdate));
    }

    private function fetchUpsertedModel(string $modelClass, array $modelData, array $uniqueKeys): Model
    {
        $query = $modelClass::query();
        foreach ($uniqueKeys as $key) {
            $query->where($key, $modelData[$key]);
        }

        return $query->first();
    }

    private function throwDuplicateEntryException(IngestConfig $config): never
    {
        $keys = is_array($config->keyedBy)
            ? implode(', ', $config->keyedBy)
            : $config->keyedBy;

        throw new RuntimeException("Duplicate entry found for key '{$keys}'.");
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
        $modelKeys = $config->getAttributesForKeyedBy();

        if (empty($modelKeys)) {
            return null;
        }

        $query = $modelClass::query();

        foreach ($modelKeys as $key) {
            if (!isset($modelData[$key])) {
                return null;
            }
            $query->where($key, $modelData[$key]);
        }

        return $query->first();
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
