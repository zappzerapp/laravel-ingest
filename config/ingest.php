<?php

declare(strict_types=1);

return [
    'path' => 'api/v1/ingest',
    'domain' => null,

    'middleware' => ['api'],

    'importers' => [
        // 'user-importer' => App\Ingest\UserImporter::class,
    ],

    'chunk_size' => 100,

    'max_show_rows' => env('INGEST_MAX_SHOW_ROWS', 100),

    'queue' => [
        'connection' => env('INGEST_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'name' => env('INGEST_QUEUE_NAME', 'imports'),
    ],

    'disk' => env('INGEST_DISK', 'local'),

    'log_rows' => true,

    'prune_days' => 30,

    'handlers' => [
        'upload' => LaravelIngest\Sources\UploadHandler::class,
        'filesystem' => LaravelIngest\Sources\FilesystemHandler::class,
        'ftp' => LaravelIngest\Sources\RemoteDiskHandler::class,
        'sftp' => LaravelIngest\Sources\RemoteDiskHandler::class,
        'url' => LaravelIngest\Sources\UrlHandler::class,
        'json-stream' => LaravelIngest\Sources\JsonHandler::class,
    ],

    'flow_engine' => [
        // Memory limit for Flow PHP operations (e.g., '256M', '512M')
        'memory_limit' => '256M',

        // Number of rows to process per chunk
        'chunk_size' => 1000,

        // Enable parallel processing of chunks (requires additional setup)
        'parallel_processing' => false,

        // Error handling strategy: 'skip_and_log', 'fail_fast', 'collect_and_report'
        'error_strategy' => 'skip_and_log',
    ],
];
