<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\IngestManager;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Sources\SourceHandlerFactory;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Tests\Fixtures\Models\User;
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
                ['<info>userimporter</info>', UserImporter::class, User::class, 'upload'],
                ['<info>productimporter</info>', ProductImporter::class, Product::class, 'filesystem'],
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

it('shows a warning when no importers are registered', function () {
    $this->app->singleton(IngestManager::class, fn($app) => new IngestManager([], $app->make(SourceHandlerFactory::class)));

    $this->artisan('ingest:list')
        ->expectsOutputToContain('No ingest definitions found.')
        ->assertExitCode(0);
});

it('shows an error in command if source file is missing', function () {
    $this->app->tag([ProductImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
    Storage::fake('local');
    config()->set('ingest.disk', 'local');

    $this->artisan('ingest:run', ['slug' => 'productimporter', '--file' => 'products.csv'])
        ->expectsOutputToContain('The ingest process could not be started.')
        ->expectsOutputToContain("We could not find the file at 'products.csv' using the disk 'local'.")
        ->assertExitCode(1);
});

it('can run an import in dry-run mode', function () {
    $fileContent = "product_sku,product_name,quantity\nSKU-001,Test Product,100";
    Storage::disk('local')->put('products.csv', $fileContent);

    $this->artisan('ingest:run', [
        'slug' => 'productimporter',
        '--file' => 'products.csv',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Running in DRY-RUN mode. No changes will be saved to the database.')
        ->assertExitCode(0);

    $this->assertDatabaseMissing('products', ['sku' => 'SKU-001']);
});
