<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed file size for uploads in bytes.
    | Default: 50MB
    |
    */
    'max_file_size' => env('INGEST_MAX_FILE_SIZE', 50 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | List of allowed MIME types for file uploads.
    |
    */
    'allowed_mime_types' => [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Directories
    |--------------------------------------------------------------------------
    |
    | List of allowed root directories for file operations to prevent
    | path traversal attacks.
    |
    */
    'allowed_directories' => [
        'ingest-uploads',
        'ingest-temp',
        'data',
        'imports',
    ],

    'allowed_url_hosts' => env('INGEST_ALLOWED_URL_HOSTS') ? explode(',', env('INGEST_ALLOWED_URL_HOSTS')) : null,

    'blocked_url_hosts' => env('INGEST_BLOCKED_URL_HOSTS') ? explode(',', env('INGEST_BLOCKED_URL_HOSTS')) : [],

    'url_timeout_seconds' => env('INGEST_URL_TIMEOUT', 15),

    'url_max_redirects' => env('INGEST_URL_MAX_REDIRECTS', 5),
];
