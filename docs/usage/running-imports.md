# Running Imports

Once an importer is defined and registered, you can start an import process in several ways, depending on your use case.

## Artisan Commands

Laravel Ingest provides several Artisan commands for managing imports.

### ingest:run

The `ingest:run` command is perfect for manual or scheduled imports where the source file is accessible on a local or configured filesystem disk.

```bash
php artisan ingest:run {slug} {--file=} {--dry-run}
```

| Argument/Option | Description |
|-----------------|-------------|
| `slug` | The slug of the importer to run (e.g., `user-importer`) |
| `--file` | Path to the source file (required for `FILESYSTEM` sources) |
| `--dry-run` | Simulate the import without saving data |

```bash
# Run a product import from a file on the default disk
php artisan ingest:run product-importer --file="imports/products.csv"

# Perform a dry run to check for errors
php artisan ingest:run product-importer --file="imports/products.csv" --dry-run
```

### ingest:list

List all registered importers and their configurations.

```bash
php artisan ingest:list
```

### ingest:status

Check the status of a specific import run.

```bash
php artisan ingest:status {id}
```

| Argument | Description |
|----------|-------------|
| `id` | The ID of the IngestRun to check |

### ingest:cancel

Cancel a running import. This will stop processing new chunks but won't roll back already processed rows.

```bash
php artisan ingest:cancel {id}
```

| Argument | Description |
|----------|-------------|
| `id` | The ID of the IngestRun to cancel |

### ingest:retry

Retry failed rows from a previous import run.

```bash
php artisan ingest:retry {id} {--dry-run}
```

| Argument/Option | Description |
|-----------------|-------------|
| `id` | The ID of the IngestRun to retry |
| `--dry-run` | Simulate the retry without saving data |

### ingest:prune-files

Clean up old temporary and uploaded files to free disk space.

```bash
php artisan ingest:prune-files {--hours=24}
```

| Option | Default | Description |
|--------|---------|-------------|
| `--hours` | `24` | Delete files older than this many hours |

This command removes files from the `ingest-temp` and `ingest-uploads` directories that are older than the specified number of hours.

#### Scheduling File Cleanup

Add to your `routes/console.php` or scheduler:

```php
// Clean up files older than 24 hours, daily
Schedule::command('ingest:prune-files')->daily();

// Clean up files older than 6 hours, every hour
Schedule::command('ingest:prune-files --hours=6')->hourly();
```

#### Row Log Pruning

To automatically prune old row logs (stored in `ingest_rows` table), add Laravel's model pruning command to your scheduler:

```php
// In routes/console.php or App\Console\Kernel
Schedule::command('model:prune')->daily();
```

The retention period is configured via `prune_days` in `config/ingest.php` (default: 30 days).

---

### 2. Via API

Laravel Ingest automatically registers API endpoints to trigger and manage imports. These are ideal for integrations or when providing a user interface for uploads.

#### File Uploads (`UPLOAD` source)
To start an import that uses `SourceType::UPLOAD`, send a `multipart/form-data` POST request.

-   **Endpoint:** `POST /api/v1/ingest/upload/{importerSlug}`
-   **Body:** Must contain a `file` field with the uploaded file. You can also include an optional `dry_run` field set to `1` or `true`.

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/users.csv" \
  -F "dry_run=1" \
  https://myapp.com/api/v1/ingest/upload/user-importer
```

#### Triggering Other Sources (`FTP`, `URL`, etc.)
For importers that don't require a file upload (like FTP or URL sources), you can trigger them with a simple POST request.

-   **Endpoint:** `POST /api/v1/ingest/trigger/{importerSlug}`

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  https://myapp.com/api/v1/ingest/trigger/daily-stock-importer
```

---

### 3. Programmatically (Facade)

You can start an import directly from your application code using the `Ingest` Facade. This is useful for complex workflows, scheduled jobs, or custom controllers.

```php
use LaravelIngest\Facades\Ingest;
use Illuminate\Support\Facades\Auth;

$slug = 'product-importer';
$filePath = 'imports/products.csv'; // Path on a configured disk
$user = Auth::user();

// Start the import
$ingestRun = Ingest::start(
    importer: $slug, 
    payload: $filePath, 
    user: $user,
    isDryRun: false
);

// $ingestRun is the Eloquent model for the newly created run.
echo "Started ingest run with ID: " . $ingestRun->id;
```
