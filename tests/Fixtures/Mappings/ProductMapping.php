<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Mappings;

use LaravelIngest\Contracts\MappingInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\Transformers\NumericTransformer;

class ProductMapping implements MappingInterface
{
    public function apply(IngestConfig $config, string $prefix = ''): IngestConfig
    {
        $prefix = $prefix !== '' ? "{$prefix}_" : '';

        return $config
            ->map("{$prefix}product_id", 'product_id')
            ->map("{$prefix}product_name", 'name')
            ->mapAndTransform(
                "{$prefix}price_cents",
                'price',
                new NumericTransformer(decimals: 2)
            )
            ->map("{$prefix}sku", 'sku');
    }
}
