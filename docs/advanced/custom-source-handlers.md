---
label: Custom Source Handlers
order: 70
---

# Custom Source Handlers

Sometimes CSVs aren't enough. You might need to import data from a JSON API, an XML feed, or a Google Sheet. You can add support for any data source by creating a custom **Source Handler**.

## The Concept

A Source Handler is responsible for one thing: **Converting a raw source into a Generator of arrays.**

Laravel Ingest doesn't care where the data comes from, as long as you yield it row by row. This ensures memory efficiency even for massive datasets.

## Tutorial: Building a JSON Array Handler

Let's build a handler that accepts a raw JSON array string. This is useful for importing data directly from a frontend POST request body.

### 1. Implement the Interface

Create `app/Ingest/Handlers/JsonStringHandler.php`:

```php
namespace App\Ingest\Handlers;

use Generator;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\IngestConfig;

class JsonStringHandler implements SourceHandler
{
    protected ?int $count = 0;

    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        // $payload will be the raw JSON string passed to IngestManager::start()
        if (!is_string($payload)) {
            throw new \InvalidArgumentException("Payload must be a JSON string");
        }

        $data = json_decode($payload, true);

        if (!is_array($data)) {
            throw new \InvalidArgumentException("Invalid JSON provided");
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

Add it to `config/ingest.php`. You can choose any key you like.

```php
// config/ingest.php
'handlers' => [
    'upload' => LaravelIngest\Sources\UploadHandler::class,
    // ...
    'json_string' => App\Ingest\Handlers\JsonStringHandler::class,
],
```

### 3. Use It

Update your Importer to use the new source type (you'll need to define the enum or just use the string key if you modify the typing, but ideally, you map it to a `SourceType` case or cast it).

*Note: Since SourceType is an Enum in the core package, for custom handlers, you might strictly need to map it to an existing Enum case (like overriding `SourceType::SFTP` if unused) OR extend the package to allow string keys. For this tutorial, we assume we override an unused type or the package allows dynamic mapping.*

```php
// In your IngestDefinition
->fromSource(SourceType::SFTP) // Mapped to 'json_string' in config
```

Now you can trigger it:

```php
$json = '[{"name": "Item 1"}, {"name": "Item 2"}]';
$manager->start('my-importer', $json);
```