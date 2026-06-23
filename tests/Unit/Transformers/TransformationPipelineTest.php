<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\Services\DataTransformationService;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Transformers\BooleanTransformer;
use LaravelIngest\Transformers\DefaultValueTransformer;
use LaravelIngest\Transformers\NumericTransformer;

it('processes transformation pipeline in sequence', function () {
    $service = new DataTransformationService();

    $mappings = [
        'price' => [
            'attribute' => 'final_price',
            'transformers' => [
                new NumericTransformer(decimals: 2),
                new DefaultValueTransformer(0),
            ],
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['price' => '123.456'], $mappings, IngestConfig::for(Product::class));

    expect($result['final_price'])->toBe(123.46);
});

it('processes pipeline with multiple different transformers', function () {
    $service = new DataTransformationService();

    $mappings = [
        'value' => [
            'attribute' => 'result',
            'transformers' => [
                new NumericTransformer(),
                new BooleanTransformer(),
            ],
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['value' => '1'], $mappings, IngestConfig::for(Product::class));

    expect($result['result'])->toBe(1);
});

it('handles empty transformer array', function () {
    $service = new DataTransformationService();

    $mappings = [
        'name' => [
            'attribute' => 'product_name',
            'transformers' => [],
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['name' => 'Test Product'], $mappings, IngestConfig::for(Product::class));

    expect($result['product_name'])->toBe('Test Product');
});

it('supports backward compatibility with single transformer', function () {
    $service = new DataTransformationService();

    $mappings = [
        'price' => [
            'attribute' => 'final_price',
            'transformer' => new NumericTransformer(decimals: 2),
            'transformers' => [],
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['price' => '123.456'], $mappings, IngestConfig::for(Product::class));

    expect($result['final_price'])->toBe(123.46);
});

it('processes conditional mappings when condition is met', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $conditionalMappings = [
        [
            'sourceField' => 'status',
            'attribute' => 'order_status',
            'condition' => fn($row) => $row['type'] === 'order',
            'transformer' => null,
            'validator' => null,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['type' => 'order', 'status' => 'pending'],
        $conditionalMappings,
        $config
    );

    expect($result)->toHaveKey('order_status')
        ->and($result['order_status'])->toBe('pending');
});

it('skips conditional mappings when condition is not met', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $conditionalMappings = [
        [
            'sourceField' => 'status',
            'attribute' => 'order_status',
            'condition' => fn($row) => $row['type'] === 'order',
            'transformer' => null,
            'validator' => null,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['type' => 'refund', 'status' => 'processed'],
        $conditionalMappings,
        $config
    );

    expect($result)->not->toHaveKey('order_status');
});
