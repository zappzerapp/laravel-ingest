---
label: Advanced Topics
icon: beaker
order: 60
---

# Advanced Topics

This section covers advanced configuration, optimization techniques, and troubleshooting for Laravel Ingest.

## Performance Optimization for Large Imports

Choosing the right transaction mode and chunk size is crucial for performance when handling large datasets.

### Transaction Mode Recommendations

| Scenario               | Recommended Setting          | Reasoning                                  |
|------------------------|------------------------------|--------------------------------------------|
| 100–10,000 rows        | `transactionMode(ROW)`       | Safety > Speed                             |
| 10,000–100,000 rows    | `transactionMode(CHUNK)`     | Balance between safety and speed            |
| >100,000 rows          | `transactionMode(NONE)`      | Speed > Safety                             |
|                        | `setChunkSize(1000)`         | Reduces queue overhead                     |

### Chunk Size Optimization

```php
// For simple inserts (less memory)
->setChunkSize(1000)  // Default: 100

// For complex transformations (more memory)
->setChunkSize(50)

// For maximum performance with large datasets
->setChunkSize(2000)
->transactionMode(TransactionMode::NONE)
```

### Queue Worker Optimization

```bash
# For large imports with more memory
php artisan queue:work --memory=1024 --timeout=600

# For fast processing of small chunks
php artisan queue:work --memory=256 --timeout=60
```

### Database Optimization

```php
// Temporarily disable indexes for maximum insert speed
->beforeRow(function(array &$row) {
    // Execute before import
    DB::statement('SET UNIQUE_CHECKS=0');
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
})

->afterRow(function($model, array $row) {
    // Execute after import
    DB::statement('SET UNIQUE_CHECKS=1');
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
})
```

## Memory-Management

### Strategies for Large Files

1. **Use URL sources instead of uploads** for very large files
2. **Reduce chunk size** when encountering memory limit errors
3. **Disable row logging** for very large imports:
   ```php
   // config/ingest.php
   'log_rows' => false,
   ```

### PHP Configuration

```ini
; php.ini
memory_limit = 1G
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
```

## Error Handling and Recovery

### Retry Strategies

```php
// Automatic retry for transient errors
->validate([
    'email' => 'required|email',
    'phone' => 'nullable|regex:/^\+?[1-9]\d{1,14}$/',
])

// Clean data in beforeRow()
->beforeRow(function(array &$row) {
    $row['email'] = strtolower(trim($row['email'] ?? ''));
    $row['phone'] = preg_replace('/[^\d+]/', '', $row['phone'] ?? '');
})
```

### Error Thresholds

```php
// Configuration for maximum error count
// config/ingest.php
'max_error_percentage' => 10,  // 10% errors allowed
'max_absolute_errors' => 1000, // Max 1000 erroneous rows
```

## Monitoring and Debugging

### Detailed Logging Strategy

```php
// In your importer class
public function getConfig(): IngestConfig
{
    return IngestConfig::for(Product::class)
        ->afterRow(function(Product $product, array $row) {
            // Detailed logging for debugging
            if (app()->environment('local')) {
                Log::debug('Imported product', [
                    'id' => $product->id,
                    'source_data' => $row,
                    'memory_usage' => memory_get_usage(true),
                ]);
            }
        });
}
```

### Performance Metrics

```php
// Monitor import performance
->beforeRow(function(array &$row) {
    if (!isset($GLOBALS['import_start_time'])) {
        $GLOBALS['import_start_time'] = microtime(true);
        $GLOBALS['import_row_count'] = 0;
    }
    $GLOBALS['import_row_count']++;
})

->afterRow(function($model, array $row) {
    if ($GLOBALS['import_row_count'] % 1000 === 0) {
        $elapsed = microtime(true) - $GLOBALS['import_start_time'];
        $rows_per_sec = $GLOBALS['import_row_count'] / $elapsed;
        Log::info("Import speed: {$rows_per_sec} rows/sec");
    }
})
```

## Advanced Scenarios

### Multi-Model Imports

```php
// Import heterogeneous data into different tables
->resolveModelUsing(function(array $row) {
    return match($row['record_type']) {
        'user' => User::class,
        'admin' => AdminUser::class,
        'customer' => Customer::class,
        default => throw new InvalidArgumentException("Unknown record type: {$row['record_type']}")
    };
})
```

### Conditional Validation

```php
// Dynamic validation rules based on data
->validate(function(array $row) {
    $rules = [
        'email' => 'required|email',
    ];
    
    if ($row['user_type'] === 'premium') {
        $rules['subscription_end'] = 'required|date|after:today';
    }
    
    return $rules;
})
```

### External API Integration

```php
// Enrich data during import with external APIs
->mapAndTransform('postal_code', 'city', function($postalCode, $row) {
    $response = Http::get("https://api.postal.com/{$postalCode}");
    return $response->json('city') ?? 'Unknown';
})
```