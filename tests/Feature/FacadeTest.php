<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Facades\Ingest;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\ProductImporter;

beforeEach(function () {
    $this->app->tag([ProductImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
    Storage::fake('local');
});

it('resolves the manager via facade', function () {
    Storage::put('products.csv', "product_sku,product_name,quantity\nSKU-1,Test,10");

    $run = Ingest::start('productimporter', 'products.csv');

    expect($run)->toBeInstanceOf(IngestRun::class);
});
