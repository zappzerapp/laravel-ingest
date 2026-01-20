---
order: 10
label: API Reference
---

# API Reference

Laravel Ingest exposes a RESTful API for managing and monitoring import processes. All endpoints are prefixed with `/api/v1/ingest` (configurable via `config/ingest.php`).

### Authentication
The API routes are wrapped in the `api` middleware group by default. You will typically need to provide an authentication token (e.g., Sanctum or Passport) in the `Authorization` header.

---

### List Ingest Runs

Retrieves a paginated list of all ingest runs, sorted by the most recent.

-   **Endpoint:** `GET /`
-   **Success Response:** `200 OK` with a paginated JSON object of [IngestRunResource](#ingestrunresource-object) objects.

```bash
curl -X GET -H "Authorization: Bearer <token>" https://myapp.com/api/v1/ingest
```

---

### Show Ingest Run Details

Retrieves the details of a single ingest run, including its rows.

-   **Endpoint:** `GET /{ingestRun}`
-   **URL Parameters:**
    -   `ingestRun` (integer, required): The ID of the ingest run.
-   **Success Response:** `200 OK` with a single [IngestRunResource](#ingestrunresource-object) object.

```bash
curl -X GET -H "Authorization: Bearer <token>" https://myapp.com/api/v1/ingest/123
```

---

### Upload File and Start Run

Starts a new ingest run from a file upload.

-   **Endpoint:** `POST /upload/{importerSlug}`
-   **URL Parameters:**
    -   `importerSlug` (string, required): The slug of the importer definition (e.g., `user-importer`).
-   **Body (`multipart/form-data`):**
    -   `file` (file, required): The data file to import (e.g., CSV, XLSX).
    -   `dry_run` (boolean, optional): If `1` or `true`, performs a simulation without saving data.
-   **Success Response:** `202 Accepted` with the newly created [IngestRunResource](#ingestrunresource-object).

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/data.csv" \
  https://myapp.com/api/v1/ingest/upload/user-importer
```

---

### Trigger Non-Upload Run

Starts a new ingest run for a source that does not require a file upload (e.g., `FTP`, `URL`).

-   **Endpoint:** `POST /trigger/{importerSlug}`
-   **URL Parameters:**
    -   `importerSlug` (string, required): The slug of the importer definition.
-   **Success Response:** `202 Accepted` with the newly created [IngestRunResource](#ingestrunresource-object).

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  https://myapp.com/api/v1/ingest/trigger/daily-stock-importer
```

---

### Cancel Ingest Run

Sends a cancellation request to a running ingest batch.

-   **Endpoint:** `POST /{ingestRun}/cancel`
-   **URL Parameters:**
    -   `ingestRun` (integer, required): The ID of the ingest run to cancel.
-   **Success Response:** `200 OK` with `{"message": "Cancellation request sent."}`.

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  https://myapp.com/api/v1/ingest/123/cancel
```

---

### Retry Failed Ingest Run

Creates a new ingest run containing only the failed rows from a previous run.

-   **Endpoint:** `POST /{ingestRun}/retry`
-   **URL Parameters:**
    -   `ingestRun` (integer, required): The ID of the original run with failed rows.
-   **Body (`application/json`, optional):**
    -   `{"dry_run": true}`
-   **Success Response:** `202 Accepted` with the newly created retry [IngestRunResource](#ingestrunresource-object).
-   **Error Response:** `400 Bad Request` if the original run has no failed rows.

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  https://myapp.com/api/v1/ingest/123/retry
```

---

### IngestRunResource Object

The standard JSON representation for an ingest run.

```json
{
  "data": {
    "id": 123,
    "importer": "user-importer",
    "status": "processing",
    "user_id": 1,
    "original_filename": "users.csv",
    "progress": {
      "total": 1000,
      "processed": 500,
      "successful": 498,
      "failed": 2
    },
    "summary": null,
    "started_at": "2023-10-27T10:00:00.000000Z",
    "completed_at": null,
    "rows": [ /* collection of IngestRowResource objects */ ]
  }
}
```