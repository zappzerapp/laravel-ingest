<?php

declare(strict_types=1);

use LaravelIngest\Contracts\MappingInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\NestedIngestConfig;
use LaravelIngest\Services\DataTransformationService;
use LaravelIngest\Tests\Fixtures\Mappings\ProductMapping;
use LaravelIngest\Tests\Fixtures\Models\Order;

it('applies a reusable mapping inside a nest block', function () {
    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->nest('line_items', function (NestedIngestConfig $nested) {
            $nested->applyMapping(new ProductMapping(), 'item');
        });

    $nested = $config->nestedConfigs['line_items'];

    expect($nested->getMappings())
        ->toHaveKey('item_product_id')
        ->toHaveKey('item_product_name')
        ->toHaveKey('item_price_cents')
        ->toHaveKey('item_sku');
});

it('transforms nested data using a reusable mapping', function () {
    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->nest('items', function (NestedIngestConfig $nested) {
            $nested->applyMapping(new ProductMapping());
        });

    $service = new DataTransformationService();

    $input = [
        'items' => [
            [
                'product_id' => 'P-001',
                'product_name' => 'Widget',
                'price_cents' => '12345',
                'sku' => 'SKU-A',
            ],
            [
                'product_id' => 'P-002',
                'product_name' => 'Gadget',
                'price_cents' => '67890',
                'sku' => 'SKU-B',
            ],
        ],
    ];

    $result = $service->processNestedData($input, $config->nestedConfigs);

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(2)
        ->and($result['items'][0])->toBe([
            'product_id' => 'P-001',
            'name' => 'Widget',
            'price' => 12345.0,
            'sku' => 'SKU-A',
        ])
        ->and($result['items'][1])->toBe([
            'product_id' => 'P-002',
            'name' => 'Gadget',
            'price' => 67890.0,
            'sku' => 'SKU-B',
        ]);
});

it('preserves fluent chaining when applying a mapping inside nest', function () {
    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->nest('line_items', function (NestedIngestConfig $nested) {
            $nested
                ->applyMapping(new ProductMapping())
                ->mapAndTransform('quantity', 'qty', fn($v) => (int) $v);
        })
        ->map('customer_email', 'email');

    $nested = $config->nestedConfigs['line_items'];

    expect($nested->getMappings())
        ->toHaveKey('product_id')
        ->toHaveKey('product_name')
        ->toHaveKey('price_cents')
        ->toHaveKey('sku')
        ->toHaveKey('quantity')
        ->and($nested->getTransformers())->toHaveKey('quantity');
});

it('ignores non-nested-aware mappings silently in nest blocks', function () {
    $plainMapping = new class() implements MappingInterface
    {
        public function apply(IngestConfig $config, string $prefix = ''): IngestConfig
        {
            return $config->map("{$prefix}field", 'attribute');
        }
    };

    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->nest('line_items', function (NestedIngestConfig $nested) use ($plainMapping) {
            $nested->applyMapping($plainMapping);
        });

    $nested = $config->nestedConfigs['line_items'];

    expect($nested->getMappings())->toBeEmpty();
});

it('allows same mapping to be used at top level and in nest', function () {
    $config = IngestConfig::for(Order::class)
        ->applyMapping(new ProductMapping(), 'master')
        ->nest('line_items', function (NestedIngestConfig $nested) {
            $nested->applyMapping(new ProductMapping(), 'detail');
        });

    expect($config->mappings)->toHaveKey('master_product_id')
        ->and($config->mappings)->toHaveKey('master_sku');

    $nested = $config->nestedConfigs['line_items'];

    expect($nested->getMappings())->toHaveKey('detail_product_id')
        ->and($nested->getMappings())->toHaveKey('detail_sku')
        ->and($nested->getMappings())->toHaveKey('detail_price_cents');
});
