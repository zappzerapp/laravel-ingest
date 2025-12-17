# Monitoring, Cancellation, and Retries

After starting an import, you can monitor its progress, cancel it if necessary, or retry any failed rows. These actions can be performed via Artisan commands or the API.

### Monitoring Status

Each import process creates an `IngestRun` record in the database. You can query this record to get detailed information about the status and progress of the import.

#### Via Artisan Command
Use the `ingest:status` command with the run ID.

```bash
php artisan ingest:status 1
```

This will display a detailed summary, including status, progress counts, and a progress bar for running imports. If the run failed, it will also display the error message.

#### Via API
Make a GET request to the `show` endpoint.

-   **Endpoint:** `GET /api/v1/ingest/{ingestRun}`

```bash
curl -X GET \
  -H "Authorization: Bearer <token>" \
  https://myapp.com/api/v1/ingest/1
```
The response will be a JSON object containing the full `IngestRun` details, including a list of all processed rows if the run is configured to log them.

### Cancelling an Ingest Run

You can request to cancel a batch that is currently processing. This is useful if you start a large import by mistake.

> **Note:** Cancellation sends a signal to the Laravel Queue batch. It may take a moment for currently processing jobs to finish before the batch is marked as cancelled.

#### Via Artisan Command
```bash
php artisan ingest:cancel 1
```

#### Via API
-   **Endpoint:** `POST /api/v1/ingest/{ingestRun}/cancel`

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  https://myapp.com/api/v1/ingest/1/cancel
```

### Retrying Failed Rows

If an import completes with failed rows, you don't need to re-upload the entire file. You can create a new import run that consists *only* of the rows that failed in the original run.

#### Via Artisan Command
Use the `ingest:retry` command with the ID of the *original* failed run.

```bash
# Create a new run with the failed rows from run #1
php artisan ingest:retry 1

# You can also perform a dry run of the retry
php artisan ingest:retry 1 --dry-run
```
This will create a new `IngestRun` and queue the jobs. You will get a new run ID to monitor.

#### Via API
-   **Endpoint:** `POST /api/v1/ingest/{ingestRun}/retry`

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  https://myapp.com/api/v1/ingest/1/retry
```
The response will be the JSON representation of the *new* ingest run that has been created.