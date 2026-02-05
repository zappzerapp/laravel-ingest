---
label: Introduction
icon: home
order: 100
---

<p align="center">
    <img src="https://raw.githubusercontent.com/zappzerapp/laravel-ingest/refs/heads/main/.github/header.png" alt="Laravel Ingest Banner" width="50%">
</p>

# Introduction to Laravel Ingest

**Stop writing spaghetti code for imports.**

Laravel Ingest is a robust, configuration-driven ETL (Extract, Transform, Load) framework for Laravel. It replaces fragile, procedural import scripts with elegant, declarative configuration classes.

The system handles the "dirty" work—file processing, streaming, validation, background jobs, error reporting, and API provision—so you can focus on the business logic.

## The Core Problem We Solve

Importing data (CSV, Excel, etc.) is often a painful process: repetitive code, lack of robustness with large files, poor user experience, and inadequate error handling. Laravel Ingest solves this with a **declarative, configuration-driven approach**.

## Key Features

-   **♾️ Infinite Scalability:** Uses Generators and Queues to process files of *any* size with flat memory usage.
-   **📝 Declarative Syntax:** Define *what* to import, not *how* to loop over it, using a fluent `IngestConfig` class.
-   **🧪 Dry Runs:** Simulate imports to find validation errors without touching the database.
-   **🔗 Auto-Relations:** Automatically resolves `BelongsTo` and `BelongsToMany` relationships (e.g., finding IDs by names).
-   **🛡️ Robust Error Handling:** Tracks every failed row and allows you to download a CSV of *only* the failures to fix and retry.
-   **🔌 API & CLI Ready:** Comes with auto-generated API endpoints and Artisan commands for full control.
-   **🗺️ Source Agnostic:** Import from file uploads, (S)FTP servers, URLs, or any Laravel filesystem disk (`s3`, `local`).
-   **🎭 Column Aliases:** Support multiple header names for the same field (e.g., `['email', 'E-Mail', 'user_email']`).
-   **🚀 Dynamic Model Resolution:** Route rows to different Eloquent models based on row data.
