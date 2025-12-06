<?php

return [
    'chunk_size' => 100,

    'queue' => [
        'connection' => env('INGEST_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'name' => env('INGEST_QUEUE_NAME', 'imports'),
    ],

    'disk' => env('INGEST_DISK', 'local'),

    'log_rows' => true,

    'handlers' => [
        'upload'     => \LaravelIngest\Sources\UploadHandler::class,
        'filesystem' => \LaravelIngest\Sources\FilesystemHandler::class,
        'ftp'        => \LaravelIngest\Sources\RemoteDiskHandler::class,
        'sftp'       => \LaravelIngest\Sources\RemoteDiskHandler::class,
        'url'        => \LaravelIngest\Sources\UrlHandler::class,
    ],
];