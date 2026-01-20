# Laravel Ingest

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zappzerapp/laravel-ingest.svg?style=flat-square)](https://packagist.org/packages/zappzerapp/laravel-ingest)
[![Total Downloads](https://img.shields.io/packagist/dt/zappzerapp/laravel-ingest.svg?style=flat-square)](https://packagist.org/packages/zappzerapp/laravel-ingest)

Laravel Ingest revolutionizes the way Laravel applications import data. We end the chaos of custom, error-prone import
scripts and provide an elegant, declarative, and robust framework for defining complex data import processes.

The system handles the "dirty" work—file processing, streaming, validation, background jobs, error reporting, and API
provision—so you can focus on the business logic.

## The Core Problem We Solve

Importing data (CSV, Excel, etc.) is often a painful process: repetitive code, lack of robustness with large files, poor
user experience, and inadequate error handling. Laravel Ingest solves this with a **declarative, configuration-driven
approach**.

## Key Features

- **Limitless Scalability**: By consistently utilizing **streams and queues**, there is no limit to file size. Whether
  100 rows or 10 million, memory usage remains consistently low.
- **Fluent & Expressive API**: Define imports in a readable and self-explanatory way using the `IngestConfig` class.
- **Source Agnostic**: Import from file uploads, (S)FTP servers, URLs, or any Laravel filesystem disk (`s3`, `local`).
  Easily extensible for other sources.
- **Robust Background Processing**: Uses the Laravel Queue by default for maximum reliability.
- **Comprehensive Mapping & Validation**: Transform data on-the-fly, resolve relationships, and use the validation rules
  of your Eloquent models.
- **Auto-generated API & CLI**: Control and monitor imports via RESTful endpoints or the included Artisan commands.
- **"Dry Runs"**: Simulate an import to detect validation errors without writing a single database entry.

## Installation

```bash
composer require zappzerapp/laravel-ingest
```

Publish the configuration and migrations:

```bash
php artisan vendor:publish --provider="LaravelIngest\IngestServiceProvider"
```

Run the migrations to create the `ingest_runs` and `ingest_rows` tables:

```bash
php artisan migrate
```

## "Hello World": Your First Importer

### 1. Define Importer Class

Create a class that implements the `IngestDefinition` interface. This is where you define the entire process.

```php
// app/Ingest/UserImporter.php
namespace App\Ingest;

use App\Models\User;
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\IngestConfig;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Enums\DuplicateStrategy;

class UserImporter implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for(User::class)
            ->fromSource(SourceType::UPLOAD)
            ->keyedBy('email')
            ->onDuplicate(DuplicateStrategy::UPDATE)
            ->map('full_name', 'name')
            ->map('user_email', 'email')
            ->mapAndTransform('is_admin', 'is_admin', fn($value) => $value === 'yes')
            ->validateWithModelRules();
    }
}
```

### 2. Tag the Model

To let the framework find your importer, tag it in the `register` method of your `AppServiceProvider`:

```php
// app/Providers/AppServiceProvider.php
use App\Ingest\UserImporter;
use LaravelIngest\IngestServiceProvider;

public function register(): void
{
    $this->app->tag([UserImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
}
```

### 3. Run Import

**Via API:** Send a `multipart/form-data` request with a `file` payload to the automatically generated endpoint. The
importer slug is derived from the class name (`UserImporter` -> `user-importer`).

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/users.csv" \
  https://myapp.com/api/v1/ingest/upload/user-importer
```

**Via CLI:**

```bash
php artisan ingest:run user-importer --file=path/to/users.csv
```

The import is now processed in the background. You can check the status via the API: `GET /api/v1/ingest/{run-id}`.

## Programmatic Usage (Facade)

You can define complex workflows or custom controllers using the `Ingest` facade.

```php
use LaravelIngest\Facades\Ingest;
use Illuminate\Support\Facades\Auth;

// Start an import
$run = Ingest::start(
    importer: 'user-importer',
    payload: '/path/to/file.csv', // Or UploadedFile instance
    user: Auth::user(),
    isDryRun: false
);

echo "Import started with ID: {$run->id}";

// Retry failed rows from a previous run
$retryRun = Ingest::retry($run);
```

## Configuration Reference (`IngestConfig`)

All configurations are handled via the fluent API in your `getConfig()` method.

| Method                                      | Description                                                                                                   |
|---------------------------------------------|---------------------------------------------------------------------------------------------------------------|
| `fromSource(SourceType, array)`             | Defines the data source (e.g., `UPLOAD`, `FTP`, `URL`, `FILESYSTEM`).                                         |
| `keyedBy(string)`                           | Sets the unique field in the source data (e.g., `sku`, `email`).                                              |
| `onDuplicate(DuplicateStrategy)`            | Defines behavior for duplicates (`UPDATE`, `SKIP`, `FAIL`).                                                   |
| `map(string, string)`                       | Maps a source column directly to a model attribute.                                                           |
| `mapAndTransform(string, string, callable)` | Maps and transforms the value before saving.                                                                  |
| `relate(string, string, string, string)`    | Resolves a `BelongsTo` relationship. Maps `sourceField` to `relationName` using `relatedModel`::`relatedKey`. |
| `validate(array)`                           | Defines import-specific validation rules.                                                                     |
| `validateWithModelRules()`                  | Uses the target model's `$rules` property for validation.                                                     |
| `setChunkSize(int)`                         | Defines the number of rows per background job (Default: 100).                                                 |
| `setDisk(string)`                           | Defines the filesystem disk for `UPLOAD` or `FILESYSTEM` sources.                                             |

## Advanced Scenarios

### Nightly FTP Import

```php
// app/Ingest/DailyStockImporter.php
return IngestConfig::for(ProductStock::class)
    ->fromSource(SourceType::FTP, [
        'host' => config('services.erp.host'),
        'username' => config('services.erp.username'),
        'password' => config('services.erp.password'),
        'path' => '/stock/daily_update.csv',
        'disk' => 'ftp_disk' // Ensure this disk is defined in filesystems.php
    ])
    ->keyedBy('product_sku')
    ->onDuplicate(DuplicateStrategy::UPDATE)
    ->map('SKU', 'product_sku')
    ->map('Quantity', 'quantity');
```

Set up a scheduled command to trigger the import:

```php
// app/Console/Kernel.php
$schedule->command('ingest:run daily-stock-importer')->dailyAt('03:00');
```

## Testing

To ensure a consistent test environment, we recommend running tests via Docker.

**Prerequisites:**
Start the environment once:

```bash
composer docker:up
```

**Run Tests:**

```bash
# Run tests inside the container
composer docker:test

# Run tests with coverage
composer docker:coverage
```

Alternatively, you can run tests locally if you have PHP 8.3 and SQLite installed:

```bash
composer test
```

---

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Credits

- [Robin Kopp](https://github.com/zappzerapp)

## License

The GNU Affero General Public License v3.0 (AGPL-3.0). Please see [License File](LICENSE) for more information.