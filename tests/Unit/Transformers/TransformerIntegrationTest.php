<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\Services\DataTransformationService;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Transformers\BooleanTransformer;
use LaravelIngest\Transformers\DateTransformer;
use LaravelIngest\Transformers\NumericTransformer;

it('accepts transformer class string in mapAndTransform', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndTransform('price_cents', 'price', NumericTransformer::class);

    expect($config->mappings['price_cents']['transformer'])
        ->toBeInstanceOf(NumericTransformer::class);
});

it('accepts transformer instance in mapAndTransform', function () {
    $transformer = new NumericTransformer(decimals: 2);

    $config = IngestConfig::for(Product::class)
        ->mapAndTransform('price_cents', 'price', $transformer);

    expect($config->mappings['price_cents']['transformer'])
        ->toBe($transformer);
});

it('accepts closure in mapAndTransform (backward compatibility)', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndTransform('price_cents', 'price', fn($v) => $v / 100);

    expect($config->mappings['price_cents']['transformer'])
        ->toBeInstanceOf(Laravel\SerializableClosure\SerializableClosure::class);
});

it('throws exception for non-existent transformer class', function () {
    IngestConfig::for(Product::class)
        ->mapAndTransform('field', 'attribute', 'NonExistentTransformer');
})->throws(LaravelIngest\Exceptions\InvalidConfigurationException::class);

it('throws exception for class not implementing TransformerInterface', function () {
    IngestConfig::for(Product::class)
        ->mapAndTransform('field', 'attribute', stdClass::class);
})->throws(LaravelIngest\Exceptions\InvalidConfigurationException::class);

it('processes data with numeric transformer', function () {
    $service = new DataTransformationService();

    $mappings = [
        'price_cents' => [
            'attribute' => 'price',
            'transformer' => new NumericTransformer(decimals: 2),
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['price_cents' => '12345'], $mappings);

    expect($result['price'])->toBe(12345.0);
});

it('processes data with boolean transformer', function () {
    $service = new DataTransformationService();

    $mappings = [
        'is_active' => [
            'attribute' => 'active',
            'transformer' => new BooleanTransformer(),
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['is_active' => 'yes'], $mappings);

    expect($result['active'])->toBe(1);
});

it('processes data with date transformer', function () {
    $service = new DataTransformationService();

    $mappings = [
        'date' => [
            'attribute' => 'formatted_date',
            'transformer' => new DateTransformer(
                inputFormat: 'd/m/Y',
                outputFormat: 'Y-m-d'
            ),
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['date' => '15/03/2024'], $mappings);

    expect($result['formatted_date'])->toBe('2024-03-15');
});

it('maintains backward compatibility with closure transformers', function () {
    $service = new DataTransformationService();

    $mappings = [
        'price' => [
            'attribute' => 'final_price',
            'transformer' => new Laravel\SerializableClosure\SerializableClosure(
                fn($v) => $v * 1.2
            ),
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['price' => '100'], $mappings);

    expect($result['final_price'])->toBe(120.0);
});
