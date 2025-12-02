<?php

use Illuminate\Support\Facades\Storage;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Tests\Fixtures\ProductImporter;
use LaravelIngest\Tests\Fixtures\UserImporter;

beforeEach(function () {
    $this->app->tag([UserImporter::class, ProductImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
    Storage::fake('local');
    config()->set('ingest.disk', 'local');
});

it('can list all registered importers', function () {
    $this->artisan('ingest:list')
        ->expectsTable(
            ['Slug', 'Class', 'Target Model', 'Source Type'],
            [
                ['<info>userimporter</info>', UserImporter::class, \LaravelIngest\Tests\Fixtures\Models\User::class, 'upload'],
                ['<info>productimporter</info>', ProductImporter::class, \LaravelIngest\Tests\Fixtures\Models\Product::class, 'filesystem'],
            ]
        )
        ->assertExitCode(0);
});

it('can run an import from the command line', function () {
    $fileContent = "product_sku,product_name,quantity\nSKU-001,Test Product,100";
    Storage::disk('local')->put('products.csv', $fileContent);

    $this->artisan('ingest:run', ['slug' => 'productimporter', '--file' => 'products.csv'])
        ->assertExitCode(0);

    $this->assertDatabaseHas('products', ['sku' => 'SKU-001', 'name' => 'Test Product', 'stock' => 100]);
});

it('fails to run if importer slug does not exist', function () {
    $this->artisan('ingest:run', ['slug' => 'non-existent-importer'])
        ->assertExitCode(1);
});