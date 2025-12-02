<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chunk Size (Batch Processing)
    |--------------------------------------------------------------------------
    |
    | UX-Regel: Verständlich (Erkläre das 'Warum')
    | Large files are processed in small "chunks" to keep memory usage low.
    | A size of 100-1000 is usually optimal. Too small = too many jobs.
    | Too large = potential timeouts or memory limits.
    |
    */
    'chunk_size' => 100,

    /*
    |--------------------------------------------------------------------------
    | Default Queue
    |--------------------------------------------------------------------------
    |
    | Specify the default queue connection and name that should be used
    | for processing ingest jobs. You can override this in your Ingest
    | Definition class.
    |
    */
    'queue' => [
        'connection' => env('INGEST_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'name' => env('INGEST_QUEUE_NAME', 'imports'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | For uploads, this is the disk where the uploaded file will be
    | temporarily stored for processing. This disk should be accessible
    | by your queue workers.
    |
    */
    'disk' => env('INGEST_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Detailed Row Logging
    |--------------------------------------------------------------------------
    |
    | By default, the status of every single row (success, failed, etc.)
    | is logged to the `ingest_rows` table. For very high-volume imports,
    | you might want to disable this to reduce database writes. The
    | summary in `ingest_runs` will still be recorded.
    |
    */
    'log_rows' => true,
];