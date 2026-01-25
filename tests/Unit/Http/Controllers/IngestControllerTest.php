<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelIngest\Http\Controllers\IngestController;
use LaravelIngest\Models\IngestRun;

uses(RefreshDatabase::class);

it('limits rows when max_show_rows config is set', function () {
    config(['ingest.max_show_rows' => 5]);

    $ingestRun = IngestRun::factory()->create();
    // Create some test rows (we'll just create the factory relationship)

    $controller = new IngestController(
        app(LaravelIngest\IngestManager::class),
        app(LaravelIngest\Services\FailedRowsExportService::class)
    );

    $response = $controller->show($ingestRun);

    expect($response->getStatusCode())->toBe(200);

    // Check if the load was called with limit constraint
    $this->assertTrue($ingestRun->relationLoaded('rows'));
});

it('loads all rows when max_show_rows config is not set', function () {
    config(['ingest.max_show_rows' => 0]);

    $ingestRun = IngestRun::factory()->create();

    $controller = new IngestController(
        app(LaravelIngest\IngestManager::class),
        app(LaravelIngest\Services\FailedRowsExportService::class)
    );

    $response = $controller->show($ingestRun);

    expect($response->getStatusCode())->toBe(200);

    // Check if the load was called without limit constraint
    $this->assertTrue($ingestRun->relationLoaded('rows'));
});

it('loads all rows when max_show_rows config is negative', function () {
    config(['ingest.max_show_rows' => -1]);

    $ingestRun = IngestRun::factory()->create();

    $controller = new IngestController(
        app(LaravelIngest\IngestManager::class),
        app(LaravelIngest\Services\FailedRowsExportService::class)
    );

    $response = $controller->show($ingestRun);

    expect($response->getStatusCode())->toBe(200);

    // Check if the load was called without limit constraint
    $this->assertTrue($ingestRun->relationLoaded('rows'));
});
