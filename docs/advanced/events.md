# Working with Events

Laravel Ingest fires a series of events throughout the import lifecycle, allowing you to easily hook into the process to add custom logic, notifications, or logging.

You can register listeners for these events in your `EventServiceProvider`.

### `LaravelIngest\Events\IngestRunStarted`
Fired as soon as an `IngestRun` model is created and the import process begins.

-   `$ingestRun`: The `IngestRun` Eloquent model.

```php
// app/Listeners/SendIngestStartedNotification.php
use LaravelIngest\Events\IngestRunStarted;

public function handle(IngestRunStarted $event): void
{
    $user = $event->ingestRun->user;
    // $user->notify(new ImportHasStarted($event->ingestRun));
}
```

### `LaravelIngest\Events\ChunkProcessed`
Fired by a background job after it finishes processing a chunk of rows.

-   `$ingestRun`: The `IngestRun` Eloquent model.
-   `$results` (array): An array with statistics for the processed chunk, e.g., `['processed' => 100, 'successful' => 98, 'failed' => 2]`.

```php
// app/Listeners/LogChunkProgress.php
use LaravelIngest\Events\ChunkProcessed;
use Illuminate\Support\Facades\Log;

public function handle(ChunkProcessed $event): void
{
    Log::info("Chunk processed for run #{$event->ingestRun->id}. " .
              "Successful: {$event->results['successful']}.");
}
```

### `LaravelIngest\Events\RowProcessed`
Fired for *every single row* after it has been processed. This is a very powerful but potentially high-volume event.

-   `$ingestRun`: The `IngestRun` model.
-   `$status` (string): The result of the processing ('success' or 'failed').
-   `$originalData` (array): The raw data for the row from the source file.
-   `$model` (?Model): The created or updated Eloquent model instance on success, `null` on failure.
-   `$errors` (?array): An array of error details on failure, `null` on success.

### `LaravelIngest\Events\IngestRunCompleted`
Fired when the entire import batch successfully completes. This event is triggered from the `then()` callback of the Laravel Job Batch.

-   `$ingestRun`: The `IngestRun` Eloquent model, now with final statistics.

```php
// app/Listeners/SendIngestCompletedNotification.php
use LaravelIngest\Events\IngestRunCompleted;

public function handle(IngestRunCompleted $event): void
{
    // Send a summary notification to the user
}
```

### `LaravelIngest\Events\IngestRunFailed`
Fired when the import process fails. This can happen if a job in the batch throws an exception that isn't caught, or if an error occurs before the batch is even dispatched (e.g., the source file is not found).

-   `$ingestRun`: The `IngestRun` Eloquent model.
-   `$exception` (?Throwable): The exception that caused the failure, if available.

```php
// app/Listeners/AlertOnFailedIngest.php
use LaravelIngest\Events\IngestRunFailed;
use Illuminate\Support\Facades\Log;

public function handle(IngestRunFailed $event): void
{
    Log::critical("Ingest run #{$event->ingestRun->id} failed!", [
        'error' => $event->exception?->getMessage()
    ]);
}
```