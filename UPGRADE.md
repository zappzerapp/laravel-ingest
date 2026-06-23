# Upgrading to 0.6.0

This guide covers breaking changes when upgrading from **0.5.x** to **0.6.0**.

Version 0.6.0 replaces the internal row-processing loop with a [Flow PHP ETL](https://github.com/flow-php/flow) pipeline. The public `IngestConfig` API stays largely the same, but runtime behavior, dependencies, and a few callbacks change.

There is **no legacy engine toggle**. All queued chunk processing goes through `FlowEngine` and `EloquentLoader`.

---

## 1. Update dependencies

Run:

```bash
composer update zappzerapp/laravel-ingest
```

0.6.0 adds required packages:

- `flow-php/etl`
- `flow-php/etl-adapter-csv`
- `flow-php/etl-adapter-json`

Publish or merge config if you maintain a published `config/ingest.php`:

```bash
php artisan vendor:publish --tag=ingest-config --force
```

Review the new `flow_engine` section (memory limit, chunk size, error strategy). Defaults are safe; adjust only if you hit memory limits on large files.

---

## 2. Processing engine (breaking)

### Before (0.5.x)

`ProcessIngestChunkJob` called `RowProcessor::processChunk()` directly.

### After (0.6.0)

The job builds and runs a Flow ETL pipeline:

1. `FlowEngine::build()` — reads the chunk, applies `beforeRow`, attaches `EloquentLoader`
2. `FlowEngine::execute()` — runs the pipeline

`RowProcessor` is no longer used in the queue path. Do not depend on it in application code; it may be removed in a future release.

### Customization

Bind your own implementation if you extend the pipeline:

```php
use LaravelIngest\Contracts\FlowEngineInterface;
use LaravelIngest\Flow\FlowEngine;

$this->app->singleton(FlowEngineInterface::class, fn () => new FlowEngine());
```

---

## 3. `beforeRow()` callbacks (breaking)

Behavior changed because callbacks now run inside `CallbackTransformer` on Flow rows.

| Topic                   | 0.5.x (`RowProcessor`)                             | 0.6.0 (`FlowEngine`)                                  |
|-------------------------|----------------------------------------------------|-------------------------------------------------------|
| Data passed to callback | Source row fields only (`$rowData->processedData`) | Full chunk item, often `['number' => …, 'data' => …]` |
| Mutation style          | By reference: `function (array &$data) { … }`      | **Must return** the modified array                    |
| Return value            | Ignored                                            | Used to rebuild the Flow row                          |

### Migration

**Before:**

```php
->beforeRow(function (array &$data) {
    $data['full_name'] = trim($data['first_name'] . ' ' . $data['last_name']);
})
```

**After:**

```php
->beforeRow(function (array $row) {
    $data = $row['data'] ?? $row;

    $data['full_name'] = trim($data['first_name'] . ' ' . $data['last_name']);

    return isset($row['data'])
        ? array_merge($row, ['data' => $data])
        : $data;
})
```

If your callback only touches flat source columns (no `number` / `data` wrapper), returning the merged row is enough:

```php
->beforeRow(fn (array $row) => array_merge($row, ['processed_at' => now()->toIso8601String()]))
```

After upgrading, re-test any importer that uses `beforeRow()` for normalization, synthetic keys, or relation injection.

---

## 4. `RowProcessed` events (breaking)

`RowProcessor` dispatched `LaravelIngest\Events\RowProcessed` after each row.

The Flow loader **does not** dispatch `RowProcessed`. Per-row listeners will no longer run.

### Alternatives

| Need                    | Use instead                                                      |
|-------------------------|------------------------------------------------------------------|
| Per-chunk progress      | `ChunkProcessed` (still dispatched from `ProcessIngestChunkJob`) |
| After each saved model  | `afterRow()` on `IngestConfig`                                   |
| After a chunk of models | `afterChunk()` on `IngestConfig` (new in 0.6.0)                  |
| Import lifecycle        | `IngestRunStarted`, `IngestRunCompleted`, `IngestRunFailed`      |

Update listeners in `EventServiceProvider` or `withEventHandler()` integrations that relied on `RowProcessed`.

---

## 5. Row logging and chunk statistics (breaking)

In 0.5.x, `ProcessIngestChunkJob` incremented `successful_rows` / `failed_rows` from in-memory counters.

In 0.6.0, success/failure counts for a chunk are derived from the `ingest_rows` table after processing (`calculateResults()`).

**If `config('ingest.log_rows')` is `false`**, logged rows are not written, so chunk statistics may report `0` successes/failures even when rows were imported.

### Migration

- Keep `'log_rows' => true` (default) if you depend on accurate run counters or failure downloads.
- If you disable row logging for performance, treat `IngestRun` success/failed counters as unreliable until a future release addresses this.

---

## 6. New APIs (non-breaking additions)

These are optional and do not require changes unless you want them:

```php
IngestConfig::for(User::class)
    ->beforeSave(fn ($model, $data) => $model)   // hook before persist
    ->afterChunk(fn ($models, $ingestRun) => …) // after each chunk job
    ->extraFields(fn ($data) => ['source' => 'csv']) // merge extra attributes
    ->compareTimestamps('updated_at');           // alias for compareTimestamp()
```

Config block:

```php
// config/ingest.php
'flow_engine' => [
    'memory_limit' => '256M',
    'chunk_size' => 1000,
    'parallel_processing' => false,
    'error_strategy' => 'skip_and_log',
],
```

---

## 7. Unchanged for typical importers

No changes required if you only use:

- `map()`, `mapAndTransform()`, `relate()`, `relateMany()`, `keyedBy()` (including composite keys)
- `onDuplicate()`, `transactionMode()`, `validate()`, `strictHeaders()`
- Standard source types (upload, filesystem, URL, JSON stream, etc.)
- `afterRow()`, dry runs, and queue batching via `IngestManager`

Re-run imports in staging after upgrade, especially when using `beforeRow()`, custom events, or `log_rows => false`.

---

## Upgrade checklist

- [ ] `composer update` completed; Flow PHP packages present in `composer.lock`
- [ ] Published/merged `config/ingest.php` and reviewed `flow_engine` settings
- [ ] All `beforeRow()` callbacks return the modified row (and handle `data` wrapper if present)
- [ ] `RowProcessed` listeners migrated to `afterRow`, `afterChunk`, or `ChunkProcessed`
- [ ] `log_rows` left enabled if run-level success/failed counts matter
- [ ] Staging import tested on a representative file (relations, duplicates, validation failures)

---

## Version reference

| Version | Engine                          | Notes                                   |
|---------|---------------------------------|-----------------------------------------|
| 0.5.x   | `RowProcessor`                  | Direct PHP loop per chunk               |
| 0.6.0+  | `FlowEngine` + `EloquentLoader` | Flow PHP ETL pipeline; no legacy toggle |

For a full list of changes, see [CHANGELOG.md](CHANGELOG.md).
