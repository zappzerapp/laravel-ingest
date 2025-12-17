# Available Source Types

Laravel Ingest is designed to be source-agnostic. You define the source of your data using the `fromSource()` method on your `IngestConfig` object. Here are the built-in source handlers and their required options.

### `SourceType::UPLOAD`

This is the most common source type for user-facing imports. It expects a file to be provided via an API request.

-   **Payload:** An instance of `Illuminate\Http\UploadedFile`.
-   **Options:** No options are required in the `IngestConfig`.

```php
// IngestConfig
->fromSource(SourceType::UPLOAD)

// API Request
// POST /api/v1/ingest/upload/{importerSlug}
// with a multipart/form-data body containing a 'file'.
```

---

### `SourceType::FILESYSTEM`

This handler reads a file directly from one of your configured Laravel filesystem disks (e.g., `local`, `s3`). It's ideal for imports triggered by console commands or scheduled jobs.

-   **Payload:** The path to the file can be passed as a string payload to the `IngestManager::start()` method, which is what the `ingest:run --file=...` command does.
-   **Options:**
    -   `path` (string): The default path to the file if no payload is provided.
    -   `disk` (string, optional): Overrides the default disk for this specific import.

```php
// IngestConfig for a command-triggered import
->fromSource(SourceType::FILESYSTEM) // Path is provided via --file option

// IngestConfig for a hardcoded path
->fromSource(SourceType::FILESYSTEM, ['path' => 'imports/daily-products.csv', 'disk' => 's3'])
```

---

### `SourceType::URL`

This handler downloads a file from a public URL and processes it. The file is streamed to a temporary local file to keep memory usage low.

-   **Payload:** None. The URL is configured directly.
-   **Options:**
    -   `url` (string): The full URL to the file to be downloaded.

```php
// IngestConfig
->fromSource(SourceType::URL, ['url' => 'https://example.com/data/export.csv'])
```

---

### `SourceType::FTP` & `SourceType::SFTP`

These handlers use the `RemoteDiskHandler` to download a file from a remote server configured as a Laravel filesystem disk. This requires a corresponding disk configuration in `config/filesystems.php`.

-   **Payload:** None.
-   **Options:**
    -   `disk` (string): The name of the FTP/SFTP disk configured in `config/filesystems.php`.
    -   `path` (string): The path to the file on the remote server.

#### Example Filesystem Configuration

First, configure your disk in `config/filesystems.php`:
```php
// config/filesystems.php
'disks' => [
    // ...
    'erp_ftp' => [
        'driver' => 'ftp',
        'host' => env('FTP_HOST'),
        'username' => env('FTP_USERNAME'),
        'password' => env('FTP_PASSWORD'),
        // ... other options
    ],
],
```

#### Example IngestConfig

Then, use the configured disk in your importer:
```php
// IngestConfig
->fromSource(SourceType::FTP, [
    'disk' => 'erp_ftp',
    'path' => '/exports/stock-levels.csv',
])
```