---
label: Troubleshooting
order: 90
---

# Troubleshooting

## 1. "File not found" with Filesystem Sources

**Error Message**:
```
We could not find the file at '/path/to/file.csv' using the disk 'local'.
```

**Causes**:
- The path is relative (e.g. `imports/file.csv` instead of `/absolute/path/file.csv`).
- The file does not exist on the configured disk.
- The disk is not configured correctly in `config/filesystems.php`.

**Solutions**:

1. **Use absolute paths**:
   ```php
   ->fromSource(SourceType::FILESYSTEM, ['path' => base_path('storage/app/imports/file.csv')])
   ```

2. **Check existence**:
   ```php
   use Illuminate\Support\Facades\Storage;
   Storage::disk('local')->exists('imports/file.csv'); // true/false
   ```

3. **Check disk configuration**:
   ```php
   // config/filesystems.php
   'disks' => [
       'local' => [
           'driver' => 'local',
           'root' => storage_path('app'),
       ],
   ],
   ```

---

## 2. Memory Limit Errors

**Error Message**:
```
Allowed memory size of 134217728 bytes exhausted
```

**Causes**:
- The CSV/Excel file is too large for the current PHP `memory_limit`.
- Too many rows are processed at once (e.g. `chunkSize` is too large).

**Solutions**:

1. **Increase `memory_limit`** (in `php.ini`):
   ```ini
   memory_limit = 512M
   ```

2. **Use smaller chunks**:
   ```php
   ->setChunkSize(500) // Default: 100
   ```

3. **Disable transactions** (for maximum performance):
   ```php
   ->transactionMode(TransactionMode::NONE)
   ```

4. **Optimize queue worker**:
   ```bash
   php artisan queue:work --memory=512
   ```

---

## 3. "Column not found" with `strictHeaders(true)`

**Error Message**:
```
The column 'user_email' was not found in the source file headers.
```

**Causes**:
- The CSV header does not match the definition in `map()` or `relate()`.
- Case sensitivity or whitespace differences (e.g. "E-Mail" vs. "email").

**Solutions**:

1. **Use aliases**:
   ```php
   ->map(['E-Mail', 'Email', 'user_email'], 'email')
   ```

2. **Set `strictHeaders(false)`** (if the column is optional):
   ```php
   ->strictHeaders(false)
   ```

3. **Adjust CSV file** (e.g. with Excel or `sed`):
   ```bash
   # Replace spaces in headers (Linux/Mac)
   sed -i '1s/ /_/g' input.csv
   ```

---

## 4. "Connection timeout" with Large Uploads

**Error Message**:
```
Connection timeout or Request timeout exceeded
```

**Causes**:
- The upload time exceeds PHP's `max_execution_time`.
- The web server has a lower timeout setting.
- The file is too large for the upload.

**Solutions**:

1. **Adjust PHP configuration**:
   ```ini
   ; php.ini
   max_execution_time = 300
   upload_max_filesize = 100M
   post_max_size = 100M
   max_input_time = 300
   ```

2. **Increase Web Server Timeout** (nginx example):
   ```nginx
   client_max_body_size 100M;
   proxy_connect_timeout 300;
   proxy_send_timeout 300;
   proxy_read_timeout 300;
   ```

3. **Implement chunked uploads in the frontend**:
   ```javascript
   // For very large files
   const chunkSize = 1024 * 1024; // 1MB chunks
   // Implement chunk-by-chunk upload
   ```

---

## 5. "Queue worker is not running"

**Error Message**:
```
Job failed after maximum attempts
```

**Causes**:
- The queue worker is not running.
- The worker crashed or exceeded its time limit.
- Queue configuration is incorrect.

**Solutions**:

1. **Start and monitor worker**:
   ```bash
   # Start
   php artisan queue:work
   
   # Monitor with Supervisord
   php artisan queue:work --daemon --sleep=1 --tries=3
   ```

2. **Supervisor configuration** (`/etc/supervisor/conf.d/laravel-worker.conf`):
   ```ini
   [program:laravel-worker]
   process_name=%(program_name)s_%(process_num)02d
   command=php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --max-time=3600
   autostart=true
   autorestart=true
   user=www-data
   numprocs=4
   redirect_stderr=true
   stdout_logfile=/path/to/your/project/storage/logs/worker.log
   stopwaitsecs=3600
   ```

3. **Check queue status**:
   ```bash
   php artisan queue:failed
   php artisan queue:retry all
   ```

---

## 6. "Foreign key constraint violation"

**Error Message**:
```
SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row
```

**Causes**:
- `relate()` or `relateMany()` cannot find the referenced record.
- The referenced ID does not exist in the target table.
- The relationship is configured incorrectly.

**Solutions**:

1. **Enable debug mode for relationships**:
   ```php
   ->relate('category_name', 'category', Category::class, 'name', createIfMissing: true)
   ```

2. **Validate data before import**:
   ```php
   ->beforeRow(function(array &$row) {
       // Check whether category exists
       if (!empty($row['category_name'])) {
           $exists = Category::where('name', $row['category_name'])->exists();
           if (!$exists) {
               $row['category_name'] = 'Default Category'; // or throw exception
           }
       }
   })
   ```

3. **Create missing records**:
   ```php
   ->relate('category_name', 'category', Category::class, 'name', createIfMissing: true)
   ```

---

## 7. "Invalid datetime format"

**Error Message**:
```
DateTime::__construct(): Failed to parse time string
```

**Causes**:
- The date format in the CSV does not match the expected format.
- Empty or invalid date values.

**Solutions**:

1. **Implement date transformation**:
   ```php
   ->mapAndTransform('import_date', 'created_at', function($value, $row) {
       if (empty($value)) return null;
       
       // Try various formats
       $formats = ['d.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'];
       foreach ($formats as $format) {
           $date = DateTime::createFromFormat($format, $value);
           if ($date !== false) {
               return $date->format('Y-m-d H:i:s');
           }
       }
       
       throw new \InvalidArgumentException("Invalid date format: {$value}");
   })
   ```

2. **Flexible validation**:
   ```php
   ->validate([
       'import_date' => 'nullable|date_format:d.m.Y|date_format:Y-m-d'
   ])
   ```

---

## 8. Performance is Very Slow

**Symptoms**:
- Importing 10,000 rows takes several minutes.
- High CPU and memory usage.

**Causes**:
- Inefficient database queries in hooks.
- Chunk size too small.
- Missing indexes in the database.

**Solutions**:

1. **Optimize chunk size**:
   ```php
   ->setChunkSize(1000) // Increase for better performance
   ```

2. **Check database indexes**:
   ```sql
   -- Index for keyedBy column
   CREATE INDEX idx_products_email ON products(email);
   
   -- Foreign key indexes
   CREATE INDEX idx_products_category_id ON products(category_id);
   ```

3. **Optimize hooks**:
   ```php
   // ❌ Bad: N+1 Queries
   ->afterRow(function($model, $row) {
       $model->category; // Loads category individually for each row
   })
   
   // ✅ Good: Eager Loading
   ->afterRow(function($model, $row) {
       $model->load('category');
   })
   ```

4. **Optimize transactions**:
   ```php
   ->transactionMode(TransactionMode::CHUNK)
   ->setChunkSize(500) // Larger chunks with transactions
   ```

---

## 9. Debugging Techniques

### Check Log Files

1. **Laravel Logs**:
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Queue Worker Logs**:
   ```bash
   tail -f storage/logs/worker.log
   ```

3. **Log database queries**:
   ```php
   // In AppServiceProvider::boot()
   if (app()->environment('local')) {
       DB::listen(function($query) {
           Log::info($query->sql, $query->bindings);
       });
   }
   ```

### Test Import with Small Data Sets

1. **Create test CSV**:
   ```csv
   name,email,price
   Test Product,test@example.com,19.99
   Another Product,another@example.com,29.99
   ```

2. **Perform a dry-run**:
   ```bash
   php artisan ingest:run product-importer --file=test.csv --dry-run
   ```

### Step-by-Step Debugging

```php
->beforeRow(function(array &$row) {
    Log::debug('Processing row', ['row' => $row]);
})
->map('name', 'name')
->map('price', 'price')
->afterRow(function($model, $row) {
    Log::debug('Row processed', ['model_id' => $model->id]);
})
```

---

## 10. Common Configuration Errors

### Incorrect Source Type Configuration

```php
// ❌ Wrong
->fromSource(SourceType::UPLOAD, ['path' => 'file.csv'])

// ✅ Correct
->fromSource(SourceType::FILESYSTEM, ['path' => 'file.csv'])
->fromSource(SourceType::UPLOAD) // No parameters for upload
```

### Missing Permissions

```php
// In your Policy
public function import(User $user)
{
    return $user->hasPermissionTo('import-products');
}
```

### Queue Configuration

```php
// config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90, // Important for long-running jobs
        'after_commit' => false,
    ],
],
```

---

## Next Steps When Problems Occur

1. **Check logs**: Review Laravel and worker logs
2. **Validate configuration**: Make sure all paths and permissions are correct
3. **Test with small data sets**: Isolate the problem
4. **Check database performance**: Indexes and query optimization
5. **Community support**: Open an issue on GitHub with detailed information

For further help, visit the [Documentation](../index.md) or create an issue in the [GitHub Repository](https://github.com/zappzerapp/laravel-ingest).
