<?php

declare(strict_types=1);

use LaravelIngest\Contracts\SourceInterface;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\IngestManager;
use LaravelIngest\Sources\SourceHandlerFactory;
use LaravelIngest\Sources\SourceInterfaceHandler;
use LaravelIngest\Tests\Fixtures\MockProductSource;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('accepts custom source interface', function () {
    $source = new MockProductSource([
        ['id' => 1, 'name' => 'Product 1', 'price' => 100],
    ]);

    $config = IngestConfig::for(Product::class)
        ->fromSource($source);

    expect($config->sourceType)->toBe($source)
        ->and($config->sourceType)->toBeInstanceOf(SourceInterface::class);
});

it('preserves source options when using custom source', function () {
    $source = new MockProductSource([]);

    $config = IngestConfig::for(Product::class)
        ->fromSource($source, ['filter' => 'active']);

    expect($config->sourceOptions)->toBe(['filter' => 'active']);
});

it('can iterate over source data', function () {
    $data = [
        ['id' => 1, 'name' => 'Product 1'],
        ['id' => 2, 'name' => 'Product 2'],
        ['id' => 3, 'name' => 'Product 3'],
    ];

    $source = new MockProductSource($data);
    $items = iterator_to_array($source->read());

    expect($items)->toHaveCount(3)
        ->and($items[0]['name'])->toBe('Product 1');
});

it('provides schema from source', function () {
    $source = new MockProductSource([]);
    $schema = $source->getSchema();

    expect($schema)->toHaveKey('id')
        ->and($schema['id'])->toBe(['type' => 'integer', 'required' => true])
        ->and($schema)->toHaveKey('price');
});

it('provides metadata from source', function () {
    $source = new MockProductSource([
        ['id' => 1],
        ['id' => 2],
    ]);

    $metadata = $source->getSourceMetadata();

    expect($metadata)->toHaveKey('total_count')
        ->and($metadata['total_count'])->toBe(2);
});

it('resolves a custom source to the source interface handler', function () {
    $handler = (new SourceHandlerFactory())->make(new MockProductSource([]));

    expect($handler)->toBeInstanceOf(SourceInterfaceHandler::class);
});

it('imports rows from a custom source', function () {
    $source = new MockProductSource([
        ['sku' => 'SKU-001', 'name' => 'Product 1', 'stock' => 10],
        ['sku' => 'SKU-002', 'name' => 'Product 2', 'stock' => 20],
    ]);

    $config = IngestConfig::for(Product::class)
        ->fromSource($source)
        ->keyedBy('sku')
        ->map('sku', 'sku')
        ->map('name', 'name')
        ->map('stock', 'stock');

    $manager = new IngestManager(['products' => $this->createTestDefinition($config)], app(SourceHandlerFactory::class));
    $run = $manager->start('products');

    expect($run->fresh())
        ->status->toBe(IngestStatus::COMPLETED)
        ->total_rows->toBe(2)
        ->successful_rows->toBe(2);

    $this->assertDatabaseHas('products', ['sku' => 'SKU-002', 'name' => 'Product 2', 'stock' => 20]);
});

it('applies header aliases to rows from a custom source', function () {
    $source = new MockProductSource([
        ['product_sku' => 'SKU-001', 'product_name' => 'Product 1', 'quantity' => 10],
    ]);

    $config = IngestConfig::for(Product::class)
        ->fromSource($source)
        ->keyedBy('sku')
        ->map(['sku', 'product_sku'], 'sku')
        ->map(['name', 'product_name'], 'name')
        ->map(['stock', 'quantity'], 'stock');

    $manager = new IngestManager(['products' => $this->createTestDefinition($config)], app(SourceHandlerFactory::class));
    $manager->start('products');

    $this->assertDatabaseHas('products', ['sku' => 'SKU-001', 'name' => 'Product 1', 'stock' => 10]);
});

it('enforces strict headers on a custom source', function () {
    $source = new MockProductSource([
        ['sku' => 'SKU-001', 'name' => 'Product 1'],
    ]);

    $config = IngestConfig::for(Product::class)
        ->fromSource($source)
        ->strictHeaders()
        ->map('sku', 'sku')
        ->map('name', 'name')
        ->map('stock', 'stock');

    $manager = new IngestManager(['products' => $this->createTestDefinition($config)], app(SourceHandlerFactory::class));
    $manager->start('products');
})->throws(SourceException::class, "None of the required columns ['stock'] were found in the source file headers.");
