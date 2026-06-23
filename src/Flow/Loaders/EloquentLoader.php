<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Loaders;

use Exception;
use Flow\ETL\FlowContext;
use Flow\ETL\Loader;
use Flow\ETL\Row;
use Flow\ETL\Rows;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\DataTransformationService;
use LaravelIngest\Services\RelationService;
use LaravelIngest\ValueObjects\Timestamp;
use RuntimeException;
use Throwable;

class EloquentLoader implements Loader
{
    private DataTransformationService $transformationService;

    public function __construct(
        private readonly IngestConfig $config,
        private readonly IngestRun $ingestRun,
        private readonly bool $isDryRun = false,
        ?DataTransformationService $transformationService = null
    ) {
        $this->transformationService = $transformationService ?? new DataTransformationService();
    }

    public function load(Rows $rows, FlowContext $context): void
    {
        $chunk = $this->rowsToChunk($rows);

        if (empty($chunk)) {
            return;
        }

        $relationCache = $this->prefetchRelations($chunk);
        $manyRelationCache = $this->prefetchManyRelations($chunk);

        $processLogic = fn() => $this->processChunk(
            $chunk,
            $relationCache,
            $manyRelationCache
        );

        if ($this->config->transactionMode === TransactionMode::CHUNK && !$this->isDryRun) {
            DB::transaction($processLogic);
        } else {
            $processLogic();
        }
    }

    private function rowsToChunk(Rows $rows): array
    {
        $chunk = [];
        $rowNumber = 0;

        foreach ($rows as $row) {
            $entries = $this->extractRowData($row);

            if (array_key_exists('number', $entries)) {
                $rowNumber = $entries['number'];
            } else {
                $rowNumber++;
            }

            $chunk[] = [
                'number' => $rowNumber,
                'data' => $entries['data'] ?? $entries,
            ];
        }

        return $chunk;
    }

    private function extractRowData(Row $row): array
    {
        $data = [];

        foreach ($row->entries() as $entry) {
            $value = $entry->value();
            if ($value instanceof \Flow\Types\Value\Json) {
                $value = $value->toArray();
            }
            $data[$entry->name()] = $value;
        }

        return $data;
    }

    private function processChunk(array $chunk, array $relationCache, array $manyRelationCache): void
    {
        $rowsToLog = [];
        $models = [];

        foreach ($chunk as $rowItem) {
            try {
                $model = $this->processRow($rowItem, $relationCache, $manyRelationCache);
                if ($model) {
                    $models[] = $model;
                }
                $rowsToLog[] = $this->prepareLogRow($rowItem, 'success');
            } catch (Throwable $e) {
                $this->handleChunkRowFailure($e, $rowItem, $rowsToLog);
            }
        }

        $this->executeAfterChunkCallback($models);
        $this->logRowsIfEnabled($rowsToLog);
    }

    private function handleChunkRowFailure(Throwable $e, array $rowItem, array &$rowsToLog): void
    {
        if ($this->shouldPropagateTestingException($e)) {
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        $rowsToLog[] = $this->prepareLogRow($rowItem, 'failed', $this->formatErrors($e));

        if ($this->config->transactionMode === TransactionMode::CHUNK && !$this->isDryRun) {
            throw $e;
        }
    }

    private function shouldPropagateTestingException(Throwable $e): bool
    {
        if (config('app.env') !== 'testing') {
            return false;
        }

        if ($e instanceof RuntimeException) {
            return true;
        }

        return str_contains($e->getMessage(), 'beforeSave callback must return an Eloquent model');
    }

    private function executeAfterChunkCallback(array $models): void
    {
        if ($this->config->afterChunkCallback && !empty($models)) {
            call_user_func($this->config->afterChunkCallback->getClosure(), $models, $this->ingestRun);
        }
    }

    private function processRow(array $rowItem, array &$relationCache, array &$manyRelationCache): ?Model
    {
        $rowLogic = function () use ($rowItem, &$relationCache, &$manyRelationCache) {
            $data = $rowItem['data'];
            $this->validate($data);

            $modelClass = $this->config->resolveModelClass($data);
            $transformedData = $this->transform($data, $relationCache, $modelClass);

            $model = null;
            if (!$this->isDryRun) {
                $model = $this->persist($transformedData, $modelClass);
                $this->syncManyRelations($model, $data, $manyRelationCache);
                $this->executeAfterRowCallback($model, $data);
            }

            return $model;
        };

        if ($this->config->transactionMode === TransactionMode::ROW && !$this->isDryRun) {
            return DB::transaction($rowLogic);
        }

        return $rowLogic();
    }

    private function validate(array $data): void
    {
        $rules = $this->config->validationRules;

        if ($this->config->useModelRules && method_exists($this->config->model, 'getRules')) {
            $rules = array_merge($this->config->model::getRules(), $rules);
        }

        if (!empty($rules)) {
            Validator::make($data, $rules)->validate();
        }
    }

    private function transform(array $data, array &$relationCache, string $modelClass): array
    {
        $modelData = $this->transformationService->processMappings($data, $this->config->mappings);

        $relationData = $this->transformationService->processRelations(
            $data,
            $this->config->relations,
            $relationCache,
            $modelClass,
            $this->isDryRun
        );

        $modelData = array_merge($modelData, $relationData, $this->resolveExtraFields($data, $modelClass));

        $unmappedData = $this->transformationService->processUnmappedData(
            $data,
            $this->buildExcludedSourceKeys(),
            $modelClass
        );

        return array_merge($modelData, $unmappedData);
    }

    private function resolveExtraFields(array $data, string $modelClass): array
    {
        if (!$this->config->extraFieldsCallback) {
            return [];
        }

        $extraFieldsData = call_user_func($this->config->extraFieldsCallback->getClosure(), $data);

        return $this->filterExtraFieldsForModel($extraFieldsData, $modelClass);
    }

    private function filterExtraFieldsForModel(array $extraFieldsData, string $modelClass): array
    {
        $modelInstance = app($modelClass);
        if (!empty($modelInstance->getGuarded()) && $modelInstance->getGuarded() !== ['*']) {
            return $extraFieldsData;
        }

        try {
            $tableColumns = \Illuminate\Support\Facades\Schema::getColumnListing($modelInstance->getTable());

            return array_intersect_key($extraFieldsData, array_flip($tableColumns));
        } catch (Exception) {
            return $extraFieldsData;
        }
    }

    private function buildExcludedSourceKeys(): array
    {
        return array_merge(
            $this->config->mappings,
            $this->config->relations,
            $this->config->manyRelations,
            $this->getUsedTopLevelKeys()
        );
    }

    private function getUsedTopLevelKeys(): array
    {
        $topLevelKeys = [];

        $sources = array_merge(
            array_keys($this->config->mappings),
            array_keys($this->config->relations),
            array_keys($this->config->manyRelations)
        );

        foreach ($sources as $sourceField) {
            if (str_contains($sourceField, '.')) {
                $topLevelKey = explode('.', $sourceField, 2)[0];
                $topLevelKeys[$topLevelKey] = true;
            }
        }

        return $topLevelKeys;
    }

    private function persist(array $modelData, string $modelClass): Model
    {
        if ($this->config->duplicateStrategy === DuplicateStrategy::UPSERT) {
            return $this->upsertModel($modelData, $modelClass);
        }

        $existingModel = $this->findExistingModel($modelData, $modelClass);

        if ($existingModel) {
            return $this->handleDuplicateStrategy($existingModel, $modelData);
        }

        return $this->createModel($modelData, $modelClass);
    }

    private function createModel(array $modelData, string $modelClass): Model
    {
        $model = new $modelClass($modelData);
        $model = $this->applyBeforeSaveCallback($model, $modelData);

        if (!$this->isDryRun) {
            $model->save();
        }

        return $model;
    }

    private function applyBeforeSaveCallback(Model $model, array $modelData): Model
    {
        if (!$this->config->beforeSaveCallback) {
            return $model;
        }

        $returnedModel = call_user_func($this->config->beforeSaveCallback->getClosure(), $model, $modelData);

        if (!$returnedModel instanceof Model) {
            throw new RuntimeException('beforeSave callback must return an Eloquent model');
        }

        return $returnedModel;
    }

    private function handleDuplicateStrategy(Model $existingModel, array $modelData): Model
    {
        return match ($this->config->duplicateStrategy) {
            DuplicateStrategy::UPDATE, DuplicateStrategy::UPSERT => $this->updateModel($existingModel, $modelData),
            DuplicateStrategy::SKIP => $existingModel,
            DuplicateStrategy::UPDATE_IF_NEWER => $this->updateIfNewer($existingModel, $modelData),
            DuplicateStrategy::FAIL => throw new RuntimeException(sprintf(
                'Duplicate entry found for key \'%s\'.',
                is_array($this->config->keyedBy)
                    ? implode(', ', $this->config->keyedBy)
                    : $this->config->keyedBy
            )),
        };
    }

    private function updateModel(Model $model, array $modelData): Model
    {
        $model->update($modelData);

        return $model->fresh();
    }

    private function updateIfNewer(Model $model, array $modelData): Model
    {
        if ($this->shouldUpdate($model, $modelData)) {
            $model->update($modelData);

            return $model->fresh();
        }

        return $model;
    }

    private function upsertModel(array $modelData, string $modelClass): Model
    {
        $uniqueKeys = $this->config->getAttributesForKeyedBy();

        if (empty($uniqueKeys)) {
            return $modelClass::create($modelData);
        }

        $model = new $modelClass();
        $this->applyUpsertTimestamps($model, $modelData);

        $updateColumns = $this->buildUpsertUpdateColumns($model, $modelData, $uniqueKeys);
        if (empty($updateColumns)) {
            return $modelClass::create($modelData);
        }

        DB::table($model->getTable())->upsert([$modelData], $uniqueKeys, $updateColumns);

        return $this->findUpsertedModel($modelClass, $modelData, $uniqueKeys);
    }

    private function applyUpsertTimestamps(Model $model, array &$modelData): void
    {
        if (!$model->usesTimestamps()) {
            return;
        }

        $now = now();
        $modelData[$model->getCreatedAtColumn()] = $now;
        $modelData[$model->getUpdatedAtColumn()] = $now;
    }

    private function buildUpsertUpdateColumns(Model $model, array $modelData, array $uniqueKeys): array
    {
        $excludeFromUpdate = array_flip($uniqueKeys);
        if ($model->usesTimestamps()) {
            $excludeFromUpdate[$model->getCreatedAtColumn()] = true;
        }

        return array_keys(array_diff_key($modelData, $excludeFromUpdate));
    }

    private function findUpsertedModel(string $modelClass, array $modelData, array $uniqueKeys): Model
    {
        $query = $modelClass::query();
        foreach ($uniqueKeys as $key) {
            $query->where($key, $modelData[$key]);
        }

        return $query->first();
    }

    private function shouldUpdate(Model $existingModel, array $newData): bool
    {
        if ($this->config->timestampComparison === null) {
            return false;
        }

        $sourceColumn = $this->config->timestampComparison['source_column'];
        $dbColumn = $this->config->timestampComparison['db_column'];

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

    private function findExistingModel(array $modelData, string $modelClass): ?Model
    {
        $modelKeys = $this->config->getAttributesForKeyedBy();

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

    private function syncManyRelations(Model $model, array $originalData, array $manyRelationCache): void
    {
        foreach ($this->config->manyRelations as $sourceField => $relationConfig) {
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

        $ids = $this->resolveManyRelationIds($originalData, $sourceField, $relationConfig, $manyRelationCache);
        if (!empty($ids)) {
            $model->{$relationConfig['relation']}()->syncWithoutDetaching($ids);
        }
    }

    private function resolveManyRelationIds(
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

        return array_values(array_filter(
            array_map(fn(string $value) => $cache[$value] ?? null, $values)
        ));
    }

    private function executeAfterRowCallback(?Model $model, array $data): void
    {
        if ($this->config->afterRowCallback && $model) {
            call_user_func($this->config->afterRowCallback->getClosure(), $model, $data);
        }
    }

    private function prefetchRelations(array $chunk): array
    {
        $cache = [];
        foreach ($this->config->relations as $sourceField => $relationConfig) {
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

    private function prefetchManyRelations(array $chunk): array
    {
        $cache = [];
        foreach ($this->config->manyRelations as $sourceField => $relationConfig) {
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
        $rawValues = collect($chunk)
            ->map(fn($item) => data_get($item, 'data.' . $sourceField))
            ->filter()
            ->values();

        if ($rawValues->isEmpty()) {
            return;
        }

        $allValues = $rawValues
            ->flatMap(fn($value) => explode($relationConfig['separator'], $value))
            ->filter()
            ->unique()
            ->values();

        if ($allValues->isEmpty()) {
            return;
        }

        $relatedModelClass = $relationConfig['model'];
        $pkName = app($relatedModelClass)->getKeyName();
        $lookupKey = $relationConfig['key'];

        $results = $relatedModelClass::query()->whereIn($lookupKey, $allValues)->get([$pkName, $lookupKey]);
        $cache[$sourceField] = $results->pluck($pkName, $lookupKey)->toArray();
    }

    private function formatErrors(Throwable $e): array
    {
        $errors = ['message' => $e->getMessage()];
        if ($e instanceof ValidationException) {
            $errors['validation'] = $e->errors();
        }

        return $errors;
    }

    private function prepareLogRow(array $rowItem, string $status, ?array $errors = null): array
    {
        try {
            $encodedData = json_encode($rowItem['data'], JSON_THROW_ON_ERROR);
            $encodedErrors = $errors ? json_encode($errors, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            $encodedData = json_encode([]);
            $encodedErrors = json_encode(['message' => 'Failed to encode row data']);
        }

        return [
            'ingest_run_id' => $this->ingestRun->id,
            'row_number' => $rowItem['number'],
            'status' => $status,
            'data' => $encodedData,
            'errors' => $encodedErrors,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function logRowsIfEnabled(array $rowsToLog): void
    {
        if (!empty($rowsToLog) && config('ingest.log_rows')) {
            IngestRow::toBase()->insert($rowsToLog);
        }
    }
}
