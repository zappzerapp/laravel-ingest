---
order: 200
label: Introduction
---

# Introduction to Laravel Ingest

Laravel Ingest revolutionizes the way Laravel applications import data. We end the chaos of custom, error-prone import scripts and provide an elegant, declarative, and robust framework for defining complex data import processes.

The system handles the "dirty" work—file processing, streaming, validation, background jobs, error reporting, and API provision—so you can focus on the business logic.

## The Core Problem We Solve

Importing data (CSV, Excel, etc.) is often a painful process: repetitive code, lack of robustness with large files, poor user experience, and inadequate error handling. Laravel Ingest solves this with a **declarative, configuration-driven approach**.

## Key Features

- **Limitless Scalability**: By consistently utilizing **streams and queues**, there is no limit to file size. Whether 100 rows or 10 million, memory usage remains consistently low.
- **Fluent & Expressive API**: Define imports in a readable and self-explanatory way using the `IngestConfig` class.
- **Source Agnostic**: Import from file uploads, (S)FTP servers, URLs, or any Laravel filesystem disk (`s3`, `local`). Easily extensible for other sources.
- **Robust Background Processing**: Uses the Laravel Queue by default for maximum reliability.
- **Comprehensive Mapping & Validation**: Transform data on-the-fly, resolve relationships, and use the validation rules of your Eloquent models.
- **Column Aliases**: Support multiple header names for the same field (e.g., `['email', 'E-Mail', 'user_email']`).
- **Dynamic Model Resolution**: Route rows to different Eloquent models based on row data.
- **Auto-Create Relations**: Automatically create missing related records during import.
- **Auto-generated API & CLI**: Control and monitor imports via RESTful endpoints or the included Artisan commands.
- **"Dry Runs"**: Simulate an import to detect validation errors without writing a single database entry.
- **Error Analysis**: Aggregated error summaries via dedicated API endpoint.