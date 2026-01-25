---
label: Configuration Files
order: 90
---

# Configuration Files

Laravel Ingest provides two configuration files that control the behavior of the package. After installation, publish them with:

```bash
php artisan vendor:publish --tag=ingest-config
```

---

## ingest.php

The main configuration file for Laravel Ingest.

### Full Reference

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Path
    |--------------------------------------------------------------------------
    |
    | The base path for the Ingest REST API endpoints.
    | All API routes will be prefixed with this path.
    |
    | Example: 'api/v1/ingest' => /api/v1/ingest/runs, /api/v1/ingest/upload, etc.
    |
    */
    'path' => 'api/v1/ingest',

    /*
    |--------------------------------------------------------------------------
    | API Domain
    |--------------------------------------------------------------------------
    |
    | Optionally restrict the API routes to a specific domain.
    | Set to null to allow all domains.
    |
    */
    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to all Ingest API routes.
    | Add authentication middleware here to protect your endpoints.
    |
    | Example: ['api', 'auth:sanctum']
    |
    */
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Registered Importers
    |--------------------------------------------------------------------------
    |
    | Register your importer classes here with a unique slug.
    | The slug is used to trigger imports via CLI or API.
    |
    | Example:
    | 'importers' => [
    |     'user-importer' => App\Ingest\UserImporter::class,
    |     'product-sync' => App\Ingest\ProductImporter::class,
    | ],
    |
    */
    'importers' => [
        // 'user-importer' => App\Ingest\UserImporter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Chunk Size
    |--------------------------------------------------------------------------
    |
    | The number of rows processed per queue job. Can be overridden
    | per-importer using IngestConfig::setChunkSize().
    |
    | - Higher values: Less queue overhead, more memory usage
    | - Lower values: More queue jobs, less memory per job
    |
    */
    'chunk_size' => 100,

    /*
    |--------------------------------------------------------------------------
    | Maximum Rows to Display
    |--------------------------------------------------------------------------
    |
    | Maximum number of row logs returned by the API when fetching
    | run details. Prevents memory issues with large imports.
    |
    */
    'max_show_rows' => env('INGEST_MAX_SHOW_ROWS', 100),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which queue connection and queue name to use for
    | processing import chunks.
    |
    | 'connection': The queue connection (redis, database, sync, etc.)
    | 'name': The queue name for import jobs
    |
    */
    'queue' => [
        'connection' => env('INGEST_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'name' => env('INGEST_QUEUE_NAME', 'imports'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used for storing uploaded and temporary files.
    | Can be overridden per-importer using IngestConfig::setDisk().
    |
    */
    'disk' => env('INGEST_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Log Individual Rows
    |--------------------------------------------------------------------------
    |
    | When enabled, each processed row is logged to the ingest_rows table.
    | This allows detailed error tracking and retry functionality.
    |
    | Disable for very large imports where row-level tracking isn't needed.
    |
    */
    'log_rows' => true,

    /*
    |--------------------------------------------------------------------------
    | Row Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain row logs before they are pruned.
    | The IngestRow model uses Laravel's Prunable trait.
    |
    | To enable automatic pruning, add to your scheduler:
    | $schedule->command('model:prune')->daily();
    |
    */
    'prune_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Source Handlers
    |--------------------------------------------------------------------------
    |
    | Maps source types to their handler classes. You can override
    | default handlers or add custom ones here.
    |
    */
    'handlers' => [
        'upload' => LaravelIngest\Sources\UploadHandler::class,
        'filesystem' => LaravelIngest\Sources\FilesystemHandler::class,
        'ftp' => LaravelIngest\Sources\RemoteDiskHandler::class,
        'sftp' => LaravelIngest\Sources\RemoteDiskHandler::class,
        'url' => LaravelIngest\Sources\UrlHandler::class,
        'json-stream' => LaravelIngest\Sources\JsonHandler::class,
    ],
];
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `INGEST_MAX_SHOW_ROWS` | `100` | Max rows returned in API responses |
| `INGEST_QUEUE_CONNECTION` | `QUEUE_CONNECTION` | Queue connection for import jobs |
| `INGEST_QUEUE_NAME` | `imports` | Queue name for import jobs |
| `INGEST_DISK` | `local` | Default storage disk |

---

## ingest_security.php

Security-related configuration to protect against malicious uploads and requests.

### Full Reference

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed file size for uploads in bytes.
    | Default: 50MB (50 * 1024 * 1024)
    |
    | Note: Also check your PHP settings (upload_max_filesize, post_max_size)
    | and web server configuration (client_max_body_size for nginx).
    |
    */
    'max_file_size' => env('INGEST_MAX_FILE_SIZE', 50 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | List of allowed MIME types for file uploads.
    | Add or remove types based on your import needs.
    |
    */
    'allowed_mime_types' => [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Directories
    |--------------------------------------------------------------------------
    |
    | List of allowed root directories for filesystem source operations.
    | This prevents path traversal attacks by restricting file access
    | to specific directories only.
    |
    | Files must be located within one of these directories.
    |
    */
    'allowed_directories' => [
        'ingest-uploads',
        'ingest-temp',
        'data',
        'imports',
    ],

    /*
    |--------------------------------------------------------------------------
    | URL Host Allowlist
    |--------------------------------------------------------------------------
    |
    | When set, only URLs from these hosts are allowed for URL source imports.
    | Leave as null to allow all hosts (subject to blocklist).
    |
    | Example: 'example.com,api.example.com'
    |
    */
    'allowed_url_hosts' => env('INGEST_ALLOWED_URL_HOSTS') 
        ? explode(',', env('INGEST_ALLOWED_URL_HOSTS')) 
        : null,

    /*
    |--------------------------------------------------------------------------
    | URL Host Blocklist
    |--------------------------------------------------------------------------
    |
    | URLs from these hosts are always blocked, even if allowlist is not set.
    | Use this to block internal networks, localhost, etc.
    |
    | Example: 'localhost,127.0.0.1,internal.company.com'
    |
    */
    'blocked_url_hosts' => env('INGEST_BLOCKED_URL_HOSTS') 
        ? explode(',', env('INGEST_BLOCKED_URL_HOSTS')) 
        : [],

    /*
    |--------------------------------------------------------------------------
    | URL Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for URL source requests.
    |
    */
    'url_timeout_seconds' => env('INGEST_URL_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | URL Maximum Redirects
    |--------------------------------------------------------------------------
    |
    | Maximum number of HTTP redirects to follow for URL source requests.
    |
    */
    'url_max_redirects' => env('INGEST_URL_MAX_REDIRECTS', 5),
];
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `INGEST_MAX_FILE_SIZE` | `52428800` (50MB) | Maximum upload file size in bytes |
| `INGEST_ALLOWED_URL_HOSTS` | `null` | Comma-separated allowlist of URL hosts |
| `INGEST_BLOCKED_URL_HOSTS` | `''` | Comma-separated blocklist of URL hosts |
| `INGEST_URL_TIMEOUT` | `15` | URL request timeout in seconds |
| `INGEST_URL_MAX_REDIRECTS` | `5` | Maximum HTTP redirects to follow |

### Security Best Practices

1. **Always set middleware authentication** in `ingest.php`:
   ```php
   'middleware' => ['api', 'auth:sanctum'],
   ```

2. **Use URL host restrictions** in production:
   ```env
   INGEST_ALLOWED_URL_HOSTS=trusted-api.com,data.example.com
   INGEST_BLOCKED_URL_HOSTS=localhost,127.0.0.1,10.0.0.0/8
   ```

3. **Restrict allowed directories** to only what's needed:
   ```php
   'allowed_directories' => [
       'imports',  // Only allow imports directory
   ],
   ```

4. **Set appropriate file size limits** based on your needs:
   ```env
   INGEST_MAX_FILE_SIZE=10485760  # 10MB for smaller imports
   ```
