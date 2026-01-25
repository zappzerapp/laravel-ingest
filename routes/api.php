<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelIngest\Http\Controllers\IngestOperationController;
use LaravelIngest\Http\Controllers\IngestReportController;
use LaravelIngest\Http\Controllers\IngestRunController;

Route::get('/', [IngestRunController::class, 'index']);
Route::get('/{ingestRun}', [IngestRunController::class, 'show']);

Route::post('/upload/{importerSlug}', [IngestOperationController::class, 'upload']);
Route::post('/trigger/{importerSlug}', [IngestOperationController::class, 'trigger']);
Route::post('/{ingestRun}/cancel', [IngestOperationController::class, 'cancel']);
Route::post('/{ingestRun}/retry', [IngestOperationController::class, 'retry']);

Route::get('/{ingestRun}/errors/summary', [IngestReportController::class, 'summary']);
Route::get('/{ingestRun}/failed-rows/download', [IngestReportController::class, 'download']);
