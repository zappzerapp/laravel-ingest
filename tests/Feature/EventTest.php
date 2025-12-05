<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Events\IngestRunCompleted;
use LaravelIngest\Events\IngestRunStarted;
use LaravelIngest\IngestManager;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Tests\Fixtures\ProductImporter;

it('dispatches events during lifecycle', function () {
    Event::fake();
    Storage::fake('local');
    Storage::put('products.csv', "product_sku,product_name,quantity\n1,Test Product,10");

    $this->app->tag([ProductImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);

    /** @var IngestManager $manager */
    $manager = app(IngestManager::class);

    $manager->start('productimporter', 'products.csv');

    Event::assertDispatched(IngestRunStarted::class);
    Event::assertDispatched(IngestRunCompleted::class);
});