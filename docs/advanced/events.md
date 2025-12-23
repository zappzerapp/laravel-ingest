---
label: Working with Events
order: 80
---

# Working with Events

Laravel Ingest dispatches events at every stage of the import lifecycle. This allows you to decouple your logic: the importer handles the data, and your listeners handle notifications, logging, or cleanup.

## Available Events

| Event Class | Description | Payload |
| :--- | :--- | :--- |
| `IngestRunStarted` | The import record is created. | `$ingestRun` |
| `ChunkProcessed` | A batch of rows finished. | `$ingestRun`, `$results` |
| `RowProcessed` | A single row was handled. | `$ingestRun`, `$status`, `$data`, `$model`, `$errors` |
| `IngestRunCompleted` | All jobs finished successfully. | `$ingestRun` |
| `IngestRunFailed` | The process crashed or stopped. | `$ingestRun`, `$exception` |

## Tutorial: Sending a Slack Notification

Let's implement a listener that sends a summary to Slack when an import completes.

### 1. Create the Notification

```bash
php artisan make:notification ImportCompletedNotification
```

```php
// app/Notifications/ImportCompletedNotification.php
public function toSlack($notifiable)
{
    $run = $this->ingestRun;
    
    return (new SlackMessage)
        ->success()
        ->content("Import '{$run->importer_slug}' finished!")
        ->attachment(function ($attachment) use ($run) {
            $attachment->fields([
                'Total' => $run->total_rows,
                'Success' => $run->successful_rows,
                'Failed' => $run->failed_rows,
            ]);
        });
}
```

### 2. Create the Listener

```bash
php artisan make:listener SendImportSummaryListener
```

```php
// app/Listeners/SendImportSummaryListener.php
namespace App\Listeners;

use LaravelIngest\Events\IngestRunCompleted;
use App\Notifications\ImportCompletedNotification;

class SendImportSummaryListener
{
    public function handle(IngestRunCompleted $event): void
    {
        $user = $event->ingestRun->user;

        if ($user) {
            $user->notify(new ImportCompletedNotification($event->ingestRun));
        }
    }
}
```

### 3. Register the Listener

In your `EventServiceProvider`:

```php
use LaravelIngest\Events\IngestRunCompleted;
use App\Listeners\SendImportSummaryListener;

protected $listen = [
    IngestRunCompleted::class => [
        SendImportSummaryListener::class,
    ],
];
```

Now, every time an import finishes, the user who started it gets a notification!