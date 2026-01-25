<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelIngest\Http\Controllers\IngestRunController;
use LaravelIngest\Models\IngestRun;

uses(RefreshDatabase::class);

it('limits rows when max_show_rows config is set', function () {
    config(['ingest.max_show_rows' => 5]);

    $ingestRun = IngestRun::factory()->create();

    $controller = new IngestRunController();

    $response = $controller->show($ingestRun);

    expect($response->getStatusCode())->toBe(200);

    $this->assertTrue($ingestRun->relationLoaded('rows'));
});

it('loads all rows when max_show_rows config is not set', function () {
    config(['ingest.max_show_rows' => 0]);

    $ingestRun = IngestRun::factory()->create();

    $controller = new IngestRunController();

    $response = $controller->show($ingestRun);

    expect($response->getStatusCode())->toBe(200);

    $this->assertTrue($ingestRun->relationLoaded('rows'));
});

it('loads all rows when max_show_rows config is negative', function () {
    config(['ingest.max_show_rows' => -1]);

    $ingestRun = IngestRun::factory()->create();

    $controller = new IngestRunController();

    $response = $controller->show($ingestRun);

    expect($response->getStatusCode())->toBe(200);

    $this->assertTrue($ingestRun->relationLoaded('rows'));
});
