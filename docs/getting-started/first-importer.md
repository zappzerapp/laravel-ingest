# Your First Importer

Let's create a simple importer to understand the core concepts of Laravel Ingest. We will build an importer for a `User` model.

### 1. Define the Importer Class

An importer is a simple PHP class that implements the `IngestDefinition` interface. This interface has a single method, `getConfig()`, which returns an `IngestConfig` object. This object declaratively defines the entire import process.

Create a new file at `app/Ingest/UserImporter.php`:

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
            ->validate([
                'full_name' => 'required|string',
                'user_email' => 'required|email'
            ]);
    }
}
```

This configuration tells Laravel Ingest to:
1.  Import data into the `User` model.
2.  Expect a file `UPLOAD` as the source.
3.  Use the `email` column to identify unique records.
4.  If a user with the same email already exists, `UPDATE` their record.
5.  Map the CSV column `full_name` to the `name` attribute on the model.
6.  Map `user_email` to `email`.
7.  Map `is_admin` to `is_admin`, transforming the value "yes" into a boolean.
8.  Validate the incoming data using the provided rules.

### 2. Register the Importer

To make your importer discoverable by the framework, you need to "tag" it in a service provider. The `AppServiceProvider` is a great place for this.

```php
// app/Providers/AppServiceProvider.php
use App\Ingest\UserImporter;
use LaravelIngest\IngestServiceProvider;

public function register(): void
{
    $this->app->tag([UserImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
}
```

Laravel Ingest automatically generates a URL-friendly "slug" from the class name. `UserImporter` becomes `user-importer`.

### 3. Run the Import

Your importer is now ready! You can trigger it via the built-in API or the command line. Prepare a CSV file named `users.csv`:

```csv
full_name,user_email,is_admin
John Doe,john@example.com,yes
Jane Smith,jane@example.com,no
```

#### Via API

Send a `multipart/form-data` POST request to the auto-generated endpoint:

```bash
curl -X POST \
  -H "Authorization: Bearer <your-api-token>" \
  -F "file=@/path/to/users.csv" \
  https://your-app.com/api/v1/ingest/upload/user-importer
```

#### Via CLI

If your file is on a disk accessible by the application, you can use the Artisan command:

```bash
php artisan ingest:run user-importer --file=path/to/users.csv
```

The import will be queued and processed in the background. You can now [monitor its progress](/usage/monitoring-and-retries/).