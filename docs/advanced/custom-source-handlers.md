# Custom Source Handlers

While Laravel Ingest provides handlers for common sources like uploads, filesystems, and URLs, you may need to import data from a custom source, such as a proprietary API, an XML feed, or a different database. You can achieve this by creating your own source handler.

### 1. The `SourceHandler` Contract

A source handler is any class that implements the `LaravelIngest\Contracts\SourceHandler` interface. This interface defines four methods:

```php
interface SourceHandler
{
    public function read(IngestConfig $config, mixed $payload = null): Generator;
    public function getTotalRows(): ?int;
    public function getProcessedFilePath(): ?string;
    public function cleanup(): void;
}
```
-   `read()`: This is the core method. It must return a `Generator` that yields one row at a time as an associative array. Using a generator is crucial for keeping memory usage low with large data sets.
-   `getTotalRows()`: Should return the total number of rows that will be processed. This is used to update the progress display.
-   `getProcessedFilePath()`: If your handler creates a temporary file, this method should return its path so it can be stored on the `IngestRun` model for debugging.
-   `cleanup()`: This method is called after the import is finished (or has failed) to allow you to clean up any temporary resources, like deleting a temp file.

### 2. Example: An `ArrayHandler`

Let's create a simple handler that processes a plain PHP array passed directly as a payload.

```php
// app/Ingest/Handlers/ArrayHandler.php
namespace App\Ingest\Handlers;

use Generator;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\IngestConfig;

class ArrayHandler implements SourceHandler
{
    protected ?int $totalRows = null;

    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('ArrayHandler expects an array payload.');
        }

        $this->totalRows = count($payload);

        foreach ($payload as $row) {
            yield $row;
        }
    }

    public function getTotalRows(): ?int
    {
        return $this->totalRows;
    }

    public function getProcessedFilePath(): ?string
    {
        // Not applicable for this handler
        return null;
    }

    public function cleanup(): void
    {
        // Nothing to clean up
    }
}
```

### 3. Registering the Handler

To make your new handler available, you need to register it in the `config/ingest.php` file. You can do this by overriding an existing, unused handler or by modifying the code to use a custom key. For simplicity, we'll override the `SFTP` handler.

```php
// config/ingest.php
'handlers' => [
    'upload' => LaravelIngest\Sources\UploadHandler::class,
    'filesystem' => LaravelIngest\Sources\FilesystemHandler::class,
    'ftp' => LaravelIngest\Sources\RemoteDiskHandler::class,
    // We register our custom handler here
    'sftp' => App\Ingest\Handlers\ArrayHandler::class,
    'url' => LaravelIngest\Sources\UrlHandler::class,
],
```

### 4. Using the Handler

Now you can use `SourceType::SFTP` in your `IngestConfig` to invoke your `ArrayHandler`.

```php
// In an IngestDefinition
use LaravelIngest\Enums\SourceType;

public function getConfig(): IngestConfig
{
    return IngestConfig::for(User::class)
        ->fromSource(SourceType::SFTP) // This now points to our ArrayHandler
        ->keyedBy('email')
        // ...
}

// When starting the import programmatically
$data = [
    ['email' => 'test1@example.com', 'name' => 'Test 1'],
    ['email' => 'test2@example.com', 'name' => 'Test 2'],
];

app(IngestManager::class)->start('user-importer', $data);
```