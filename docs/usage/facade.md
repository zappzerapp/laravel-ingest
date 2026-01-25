---
label: Facade Reference
order: 70
---

# Facade Reference

The `Ingest` Facade provides a convenient interface to interact with the IngestManager. Use it to programmatically start imports, retry failed runs, and access importer definitions.

```php
use LaravelIngest\Facades\Ingest;
```

---

## Methods

### start()

Start a new import run.

```php
public static function start(
    string $importer,
    mixed $payload = null,
    ?Authenticatable $user = null,
    bool $isDryRun = false
): IngestRun
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$importer` | `string` | The registered slug of the importer |
| `$payload` | `mixed` | Source data: file path, `UploadedFile`, URL, or `null` |
| `$user` | `?Authenticatable` | User to associate with the run (for auditing) |
| `$isDryRun` | `bool` | If `true`, validate and transform without persisting |

**Returns:** `IngestRun` - The Eloquent model for the newly created run.

**Throws:**
- `DefinitionNotFoundException` - If importer slug is not registered
- `InvalidConfigurationException` - If importer config is invalid
- `SourceException` - If source cannot be read
- `FileProcessingException` - If file validation fails

#### Examples

```php
use LaravelIngest\Facades\Ingest;
use Illuminate\Support\Facades\Auth;

// Basic usage with file path
$run = Ingest::start('product-importer', 'imports/products.csv');

// With authenticated user
$run = Ingest::start('product-importer', 'imports/products.csv', Auth::user());

// With uploaded file
$run = Ingest::start('product-importer', $request->file('import'));

// Dry run (no data saved)
$run = Ingest::start('product-importer', 'imports/products.csv', null, isDryRun: true);

// For importers with predefined sources (FTP, scheduled URL)
$run = Ingest::start('daily-stock-sync');

// Check the result
echo "Import started with ID: {$run->id}";
echo "Status: {$run->status->value}";
```

---

### retry()

Retry failed rows from a previous import run.

```php
public static function retry(
    IngestRun $originalRun,
    ?Authenticatable $user = null,
    bool $isDryRun = false
): IngestRun
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$originalRun` | `IngestRun` | The original run with failed rows |
| `$user` | `?Authenticatable` | User to associate with the retry run |
| `$isDryRun` | `bool` | If `true`, validate without persisting |

**Returns:** `IngestRun` - A new IngestRun for the retry attempt.

**Throws:**
- `NoFailedRowsException` - If the original run has no failed rows
- `ConcurrencyException` - If a retry is already in progress
- `DefinitionNotFoundException` - If importer no longer exists

#### Examples

```php
use LaravelIngest\Facades\Ingest;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Exceptions\NoFailedRowsException;

$originalRun = IngestRun::find(123);

// Check if there are failed rows first
if ($originalRun->failed_rows === 0) {
    return response()->json(['message' => 'No failed rows to retry']);
}

try {
    $retryRun = Ingest::retry($originalRun, Auth::user());
    
    echo "Retry started with ID: {$retryRun->id}";
    echo "Retrying {$originalRun->failed_rows} rows";
} catch (NoFailedRowsException $e) {
    // No failed rows to retry
} catch (ConcurrencyException $e) {
    // Another retry is already in progress
}
```

The retry run maintains a reference to the original:

```php
$retryRun->retried_from_run_id; // Original run ID
$retryRun->parent_id;           // Original run ID
```

---

### getDefinition()

Get a specific importer definition by its slug.

```php
public static function getDefinition(string $slug): IngestDefinition
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$slug` | `string` | The registered slug of the importer |

**Returns:** `IngestDefinition` - The importer class instance.

**Throws:**
- `DefinitionNotFoundException` - If slug is not registered

#### Examples

```php
use LaravelIngest\Facades\Ingest;

// Get the importer definition
$definition = Ingest::getDefinition('product-importer');

// Access the configuration
$config = $definition->getConfig();

echo "Model: " . $config->modelClass;
echo "Source: " . $config->sourceType->value;
echo "Chunk size: " . $config->chunkSize;

// Check importer details
$mappings = $config->getMappings();
$validationRules = $config->getValidationRules();
```

---

### getDefinitions()

Get all registered importer definitions.

```php
public static function getDefinitions(): array
```

**Returns:** `array` - Associative array of slug => IngestDefinition instances.

#### Examples

```php
use LaravelIngest\Facades\Ingest;

// List all importers
$definitions = Ingest::getDefinitions();

foreach ($definitions as $slug => $definition) {
    $config = $definition->getConfig();
    echo "{$slug}: imports into {$config->modelClass}\n";
}

// Check if an importer exists
$definitions = Ingest::getDefinitions();
if (isset($definitions['product-importer'])) {
    // Importer exists
}

// Build a dropdown of available importers
$options = collect(Ingest::getDefinitions())
    ->map(fn($def, $slug) => [
        'value' => $slug,
        'label' => $def->getConfig()->modelClass,
    ])
    ->values();
```

---

## Common Patterns

### Controller Integration

```php
namespace App\Http\Controllers;

use LaravelIngest\Facades\Ingest;
use LaravelIngest\Exceptions\FileProcessingException;
use LaravelIngest\Exceptions\DefinitionNotFoundException;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function store(Request $request, string $importer)
    {
        $request->validate([
            'file' => 'required|file',
            'dry_run' => 'boolean',
        ]);

        try {
            $run = Ingest::start(
                importer: $importer,
                payload: $request->file('file'),
                user: $request->user(),
                isDryRun: $request->boolean('dry_run')
            );

            return response()->json([
                'message' => 'Import started',
                'run_id' => $run->id,
                'status' => $run->status->value,
            ]);
        } catch (DefinitionNotFoundException $e) {
            return response()->json(['error' => 'Importer not found'], 404);
        } catch (FileProcessingException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
```

### Scheduled Imports

```php
// routes/console.php or App\Console\Kernel

use LaravelIngest\Facades\Ingest;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    Ingest::start('daily-stock-sync');
})->daily()->at('02:00');

Schedule::call(function () {
    Ingest::start('hourly-price-update');
})->hourly();
```

### Queued Job

```php
namespace App\Jobs;

use LaravelIngest\Facades\Ingest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $importer,
        private string $filePath,
        private ?int $userId = null
    ) {}

    public function handle()
    {
        $user = $this->userId ? User::find($this->userId) : null;
        
        Ingest::start($this->importer, $this->filePath, $user);
    }
}
```

### Event-Driven Imports

```php
namespace App\Listeners;

use LaravelIngest\Facades\Ingest;
use App\Events\DataFileReceived;

class ProcessReceivedDataFile
{
    public function handle(DataFileReceived $event)
    {
        Ingest::start(
            importer: $event->importerSlug,
            payload: $event->filePath,
            user: $event->uploadedBy
        );
    }
}
```
