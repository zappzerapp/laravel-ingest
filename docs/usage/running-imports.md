# Running Imports

Once an importer is defined and registered, you can start an import process in several ways, depending on your use case.

### 1. Via Artisan Command

The `ingest:run` command is perfect for manual or scheduled imports where the source file is accessible on a local or configured filesystem disk.

#### Command Signature
```bash
php artisan ingest:run {slug} {--file=} {--dry-run}
```

-   `slug`: The slug of the importer you want to run (e.g., `user-importer`).
-   `--file`: (Optional) The path to the source file. This is required for `FILESYSTEM` sources and will be passed as the payload.
-   `--dry-run`: (Optional) Simulates the entire import process—including validation and transformation—without saving any data to the database. This is extremely useful for testing a new file.

#### Example
```bash
# Run a product import from a file on the default disk
php artisan ingest:run product-importer --file="imports/products.csv"

# Perform a dry run to check for errors
php artisan ingest:run product-importer --file="imports/products.csv" --dry-run
```

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

### 3. Programmatically

You can start an import directly from your application code by using the `IngestManager`. This is useful for complex workflows, scheduled jobs, or custom controllers.

```php
use LaravelIngest\IngestManager;
use Illuminate\Support\Facades\Auth;

// Resolve the manager from the service container
$ingestManager = app(IngestManager::class);

$slug = 'product-importer';
$filePath = 'imports/products.csv'; // Path on a configured disk
$user = Auth::user();

// Start the import
$ingestRun = $ingestManager->start($slug, $filePath, $user);

// $ingestRun is the Eloquent model for the newly created run.
echo "Started ingest run with ID: " . $ingestRun->id;
```