<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelIngest\Http\Controllers\IngestController;

Route::prefix('api/v1/ingest')
    ->middleware('api')
    ->group(function () {
        Route::get('/', [IngestController::class, 'index']);
        Route::get('/{ingestRun}', [IngestController::class, 'show']);

        Route::post('/upload/{importerSlug}', [IngestController::class, 'upload']);
        Route::post('/trigger/{importerSlug}', [IngestController::class, 'trigger']);
        Route::post('/{ingestRun}/cancel', [IngestController::class, 'cancel']);
        Route::post('/{ingestRun}/retry', [IngestController::class, 'retry']);
    });
