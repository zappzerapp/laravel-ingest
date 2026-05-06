<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Mappings\ProductMapping;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('applies mapping via applyMapping method', function () {
    $config = IngestConfig::for(Product::class);

    $result = $config->applyMapping(new ProductMapping());

    expect($result)->toBe($config)
        ->and($result->mappings)->toHaveKey('product_id')
        ->and($result->mappings)->toHaveKey('product_name')
        ->and($result->mappings)->toHaveKey('price_cents')
        ->and($result->mappings)->toHaveKey('sku');
});

it('applies mapping with prefix via applyMapping method', function () {
    $config = IngestConfig::for(Product::class);

    $result = $config->applyMapping(new ProductMapping(), 'line_item');

    expect($result->mappings)->toHaveKey('line_item_product_id')
        ->and($result->mappings)->toHaveKey('line_item_product_name')
        ->and($result->mappings)->toHaveKey('line_item_price_cents')
        ->and($result->mappings)->toHaveKey('line_item_sku');
});

it('can chain applyMapping with other config methods', function () {
    $config = IngestConfig::for(Product::class)
        ->map('order_id', 'id')
        ->applyMapping(new ProductMapping(), 'item')
        ->keyedBy('item_product_id');

    expect($config->mappings)->toHaveKey('order_id')
        ->and($config->mappings)->toHaveKey('item_product_id')
        ->and($config->keyedBy)->toBe('item_product_id');
});

it('preserves type safety via interface contract', function () {
    $config = IngestConfig::for(Product::class);
    $mapping = new ProductMapping();

    // This would fail type checking if ProductMapping didn't implement MappingInterface
    $result = $config->applyMapping($mapping);

    expect($result)->toBeInstanceOf(IngestConfig::class);
});
