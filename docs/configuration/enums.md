---
label: Enums Reference
order: 80
---

# Enums Reference

Laravel Ingest uses several PHP 8.1+ enums for type-safe configuration. This page documents all available enums and their values.

---

## IngestStatus

Represents the current state of an import run.

**Namespace:** `LaravelIngest\Enums\IngestStatus`

```php
use LaravelIngest\Enums\IngestStatus;
```

| Value | String | Description |
|-------|--------|-------------|
| `PENDING` | `'pending'` | Run created but not yet processing |
| `PROCESSING` | `'processing'` | Currently processing rows |
| `COMPLETED` | `'completed'` | All rows processed successfully |
| `COMPLETED_WITH_ERRORS` | `'completed_with_errors'` | Finished but some rows failed |
| `FAILED` | `'failed'` | Import failed completely |

### Usage Examples

```php
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Models\IngestRun;

// Check run status
$run = IngestRun::find(1);

if ($run->status === IngestStatus::COMPLETED) {
    echo "Import completed successfully!";
}

if ($run->status === IngestStatus::COMPLETED_WITH_ERRORS) {
    echo "Import completed with {$run->failed_rows} failed rows.";
}

// Query by status
$processingRuns = IngestRun::where('status', IngestStatus::PROCESSING)->get();
$failedRuns = IngestRun::where('status', IngestStatus::FAILED)->get();

// Get string value for API responses
$statusString = $run->status->value; // 'completed', 'failed', etc.
```

### Status Flow

```
PENDING → PROCESSING → COMPLETED
                    ↘ COMPLETED_WITH_ERRORS
                    ↘ FAILED
```

---

## SourceType

Defines where import data comes from.

**Namespace:** `LaravelIngest\Enums\SourceType`

```php
use LaravelIngest\Enums\SourceType;
```

| Value | String | Description |
|-------|--------|-------------|
| `UPLOAD` | `'upload'` | HTTP file upload |
| `FILESYSTEM` | `'filesystem'` | Local or cloud filesystem (S3, etc.) |
| `FTP` | `'ftp'` | FTP server |
| `SFTP` | `'sftp'` | SFTP server |
| `URL` | `'url'` | Remote URL (HTTP/HTTPS) |
| `JSON` | `'json-stream'` | JSON file with memory-efficient streaming |

### Usage Examples

```php
use LaravelIngest\Enums\SourceType;
use LaravelIngest\IngestConfig;

// File uploads (via API)
IngestConfig::for(User::class)
    ->fromSource(SourceType::UPLOAD)

// Local/S3 filesystem
IngestConfig::for(Product::class)
    ->fromSource(SourceType::FILESYSTEM, [
        'path' => 'imports/products.csv',
        'disk' => 's3',
    ])

// FTP server
IngestConfig::for(Order::class)
    ->fromSource(SourceType::FTP, [
        'disk' => 'ftp-server',
        'path' => 'exports/orders.csv',
    ])

// SFTP server
IngestConfig::for(Invoice::class)
    ->fromSource(SourceType::SFTP, [
        'disk' => 'sftp-server',
        'path' => 'data/invoices.csv',
    ])

// Remote URL
IngestConfig::for(Rate::class)
    ->fromSource(SourceType::URL, [
        'url' => 'https://api.example.com/rates.csv',
    ])

// JSON with streaming (memory efficient for large files)
IngestConfig::for(Event::class)
    ->fromSource(SourceType::JSON, [
        'path' => 'data/events.json',
        'pointer' => '/data/items', // JSON pointer to array
    ])
```

---

## DuplicateStrategy

Defines behavior when a record with matching `keyedBy` value already exists.

**Namespace:** `LaravelIngest\Enums\DuplicateStrategy`

```php
use LaravelIngest\Enums\DuplicateStrategy;
```

| Value | String | Description |
|-------|--------|-------------|
| `SKIP` | `'skip'` | Keep existing record, don't update (default) |
| `UPDATE` | `'update'` | Overwrite existing record with new data |
| `FAIL` | `'fail'` | Mark row as failed, don't modify existing |
| `UPDATE_IF_NEWER` | `'update_if_newer'` | Update only if source is newer (requires `compareTimestamp()`) |

### Usage Examples

```php
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\IngestConfig;

// Skip duplicates (default) - good for append-only imports
IngestConfig::for(User::class)
    ->keyedBy('email')
    ->onDuplicate(DuplicateStrategy::SKIP)

// Always update - good for full syncs
IngestConfig::for(Product::class)
    ->keyedBy('sku')
    ->onDuplicate(DuplicateStrategy::UPDATE)

// Fail on duplicates - good for strict imports
IngestConfig::for(Transaction::class)
    ->keyedBy('transaction_id')
    ->onDuplicate(DuplicateStrategy::FAIL)

// Conditional update based on timestamp
IngestConfig::for(Article::class)
    ->keyedBy('external_id')
    ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
    ->compareTimestamp('last_modified', 'updated_at')
```

### Decision Guide

| Scenario | Recommended Strategy |
|----------|---------------------|
| Daily full product catalog sync | `UPDATE` |
| Importing new user registrations | `SKIP` |
| Financial transactions (no duplicates allowed) | `FAIL` |
| Incremental updates from CMS | `UPDATE_IF_NEWER` |

---

## TransactionMode

Controls database transaction behavior during import.

**Namespace:** `LaravelIngest\Enums\TransactionMode`

```php
use LaravelIngest\Enums\TransactionMode;
```

| Value | String | Description |
|-------|--------|-------------|
| `NONE` | `'none'` | No transactions, each row committed individually (default) |
| `CHUNK` | `'chunk'` | Wrap each chunk in a transaction |
| `ROW` | `'row'` | Wrap each individual row in a transaction |

### Usage Examples

```php
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\IngestConfig;

// No transactions (default) - fastest, partial imports possible
IngestConfig::for(LogEntry::class)
    ->transactionMode(TransactionMode::NONE)

// Chunk transactions - balanced approach
// If one row in a chunk fails, the entire chunk rolls back
IngestConfig::for(Order::class)
    ->transactionMode(TransactionMode::CHUNK)
    ->setChunkSize(50)

// Same as TransactionMode::CHUNK
IngestConfig::for(Order::class)
    ->atomic()

// Row transactions - safest but slowest
// Each row is isolated, useful for complex afterRow hooks
IngestConfig::for(Invoice::class)
    ->transactionMode(TransactionMode::ROW)
```

### Performance vs. Consistency Trade-offs

| Mode | Speed | Data Consistency | Use Case |
|------|-------|------------------|----------|
| `NONE` | Fastest | Partial imports possible | Logs, non-critical data |
| `CHUNK` | Medium | Chunk-level consistency | Most imports |
| `ROW` | Slowest | Row-level consistency | Complex operations with side effects |

---

## Using Enums in Queries

All enums are backed by string values, making them easy to use in database queries:

```php
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Models\IngestRun;

// Using enum directly (recommended)
$runs = IngestRun::where('status', IngestStatus::COMPLETED)->get();

// Using string value
$runs = IngestRun::where('status', 'completed')->get();

// Multiple statuses
$activeRuns = IngestRun::whereIn('status', [
    IngestStatus::PENDING,
    IngestStatus::PROCESSING,
])->get();
```

---

## Type Hints

Use enums in your own code for type safety:

```php
use LaravelIngest\Enums\IngestStatus;

function handleCompletedImport(IngestRun $run): void
{
    if ($run->status !== IngestStatus::COMPLETED) {
        throw new \InvalidArgumentException('Run must be completed');
    }
    
    // Process completed import...
}

function getStatusLabel(IngestStatus $status): string
{
    return match($status) {
        IngestStatus::PENDING => 'Waiting to start',
        IngestStatus::PROCESSING => 'In progress',
        IngestStatus::COMPLETED => 'Finished',
        IngestStatus::COMPLETED_WITH_ERRORS => 'Finished with errors',
        IngestStatus::FAILED => 'Failed',
    };
}
```
