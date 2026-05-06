<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\NestedIngestConfig;
use LaravelIngest\Tests\Fixtures\Models\Order;
use LaravelIngest\Transformers\NumericTransformer;

it('creates nested config via nest method', function () {
    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->nest('line_items', function (NestedIngestConfig $nested) {
            $nested->map('sku', 'product_sku')
                ->mapAndTransform('qty', 'quantity', NumericTransformer::class);
        });

    expect($config->nestedConfigs)->toHaveKey('line_items')
        ->and($config->nestedConfigs['line_items'])->toBeInstanceOf(NestedIngestConfig::class);
});

it('nested config stores mappings', function () {
    $config = IngestConfig::for(Order::class)
        ->nest('items', function (NestedIngestConfig $nested) {
            $nested->map('sku', 'product_sku')
                ->map('price', 'unit_price');
        });

    $nested = $config->nestedConfigs['items'];

    expect($nested->getMappings())->toHaveKey('sku')
        ->and($nested->getMappings())->toHaveKey('price');
});

it('nested config stores transformers', function () {
    $config = IngestConfig::for(Order::class)
        ->nest('items', function (NestedIngestConfig $nested) {
            $nested->mapAndTransform('qty', 'quantity', fn($v) => $v * 2);
        });

    $nested = $config->nestedConfigs['items'];

    expect($nested->getTransformers())->toHaveKey('qty');
});

it('nested config supports keyedBy', function () {
    $config = IngestConfig::for(Order::class)
        ->nest('items', function (NestedIngestConfig $nested) {
            $nested->map('sku', 'product_sku')
                ->keyedBy('sku');
        });

    $nested = $config->nestedConfigs['items'];

    expect($nested->getMappings()['_keyedBy'])->toBe('sku');
});

it('can chain nest with other config methods', function () {
    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->nest('line_items', function (NestedIngestConfig $nested) {
            $nested->map('sku', 'product_sku');
        })
        ->map('customer_email', 'email')
        ->keyedBy('order_id');

    expect($config->mappings)->toHaveKey('order_id')
        ->and($config->mappings)->toHaveKey('customer_email')
        ->and($config->nestedConfigs)->toHaveKey('line_items')
        ->and($config->keyedBy)->toBe('order_id');
});

it('nested config stores validators', function () {
    $config = IngestConfig::for(Order::class)
        ->nest('items', function (NestedIngestConfig $nested) {
            $nested->mapAndValidate('email', 'email', LaravelIngest\Validators\EmailValidator::class);
        });

    $nested = $config->nestedConfigs['items'];

    expect($nested->getValidators())->toHaveKey('email');
});

it('nested map supports aliases', function () {
    $config = IngestConfig::for(Order::class)
        ->nest('items', function (NestedIngestConfig $nested) {
            $nested->map(['sku', 'SKU'], 'product_sku');
        });

    $nested = $config->nestedConfigs['items'];

    expect($nested->getMappings()['sku']['aliases'])->toBe(['SKU']);
});

it('nested mapAndTransform supports aliases', function () {
    $config = IngestConfig::for(Order::class)
        ->nest('items', function (NestedIngestConfig $nested) {
            $nested->mapAndTransform(['qty', 'quantity'], 'amount', fn($v) => $v * 2);
        });

    $nested = $config->nestedConfigs['items'];

    expect($nested->getMappings()['qty']['aliases'])->toBe(['quantity']);
});
