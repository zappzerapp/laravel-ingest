---
label: Custom Source Handlers
order: 70
---

# Custom Source Handlers

Sometimes CSVs aren't enough. You might need to import data from a JSON API, an XML feed, or a Google Sheet. You can add support for any data source by creating a custom **Source Handler**.

## The Concept

A Source Handler is responsible for one thing: **Converting a raw source into a Generator of arrays.**

Laravel Ingest doesn't care where the data comes from, as long as you yield it row by row. This ensures memory efficiency even for massive datasets.

## The SourceHandler Interface

Every handler must implement `LaravelIngest\Contracts\SourceHandler`:

```php
interface SourceHandler
{
    /**
     * Read data from the source and yield rows as arrays.
     * @param mixed|null $payload Data from the trigger (e.g., UploadedFile, path, or custom data)
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator;

    /**
     * Return the total number of rows, or null if unknown.
     */
    public function getTotalRows(): ?int;

    /**
     * Return the path where the processed file was stored, or null.
     */
    public function getProcessedFilePath(): ?string;

    /**
     * Clean up any temporary files or resources.
     */
    public function cleanup(): void;
}
```

## Tutorial: Building a JSON Array Handler

Let's build a handler that accepts a raw JSON array string. This is useful for importing data directly from a frontend POST request body.

### 1. Implement the Interface

Create `app/Ingest/Handlers/JsonArrayHandler.php`:

```php
namespace App\Ingest\Handlers;

use Generator;
use InvalidArgumentException;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\IngestConfig;

class JsonArrayHandler implements SourceHandler
{
    protected ?int $count = null;

    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        // $payload will be the raw JSON string passed to IngestManager::start()
        if (!is_string($payload)) {
            throw new InvalidArgumentException("Payload must be a JSON string");
        }

        $data = json_decode($payload, true);

        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid JSON provided");
        }

        $this->count = count($data);

        // Yield each item. The framework handles the rest.
        foreach ($data as $item) {
            yield $item;
        }
    }

    public function getTotalRows(): ?int
    {
        return $this->count;
    }

    public function getProcessedFilePath(): ?string
    {
        return null; // We don't save a file to disk
    }

    public function cleanup(): void
    {
        // No file cleanup needed
    }
}
```

### 2. Register the Handler

Add it to `config/ingest.php` by mapping an existing `SourceType` enum value to your handler:

```php
// config/ingest.php
'handlers' => [
    'upload' => LaravelIngest\Sources\UploadHandler::class,
    'filesystem' => LaravelIngest\Sources\FilesystemHandler::class,
    'ftp' => LaravelIngest\Sources\RemoteDiskHandler::class,
    'sftp' => LaravelIngest\Sources\RemoteDiskHandler::class,
    'url' => LaravelIngest\Sources\UrlHandler::class,
    
    // Map an existing SourceType to your custom handler
    // Option 1: Override an unused type
    // 'sftp' => App\Ingest\Handlers\JsonArrayHandler::class,
],
```

### 3. Alternative: Direct Handler Injection

For more flexibility, you can bypass the SourceType enum entirely by using the handler directly in your code:

```php
use App\Ingest\Handlers\JsonArrayHandler;
use LaravelIngest\IngestManager;

// In a controller or service
$json = '[{"name": "Item 1", "email": "item1@example.com"}, {"name": "Item 2", "email": "item2@example.com"}]';

// Start the import with the custom handler
$ingestManager = app(IngestManager::class);
$run = $ingestManager->start('my-importer', $json);
```

## Example: API Response Handler

Here's a more practical example that fetches data from an external API:

```php
namespace App\Ingest\Handlers;

use Generator;
use Illuminate\Support\Facades\Http;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\IngestConfig;
use LaravelIngest\Exceptions\SourceException;

class ApiHandler implements SourceHandler
{
    protected ?int $count = null;

    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        $url = $config->sourceOptions['url'] ?? null;
        $headers = $config->sourceOptions['headers'] ?? [];

        if (!$url) {
            throw new SourceException("API URL is required in source options");
        }

        $response = Http::withHeaders($headers)->get($url);

        if (!$response->successful()) {
            throw new SourceException("API request failed: " . $response->status());
        }

        $data = $response->json('data') ?? $response->json();
        
        if (!is_array($data)) {
            throw new SourceException("API response must contain an array");
        }

        $this->count = count($data);

        foreach ($data as $item) {
            yield $item;
        }
    }

    public function getTotalRows(): ?int
    {
        return $this->count;
    }

    public function getProcessedFilePath(): ?string
    {
        return null;
    }

    public function cleanup(): void
    {
        // Nothing to clean up
    }
}
```