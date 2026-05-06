<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Mappings;

use LaravelIngest\Contracts\HasMappings;
use LaravelIngest\Contracts\MappingInterface;
use LaravelIngest\Contracts\NestedMappingInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\NestedIngestConfig;
use LaravelIngest\Transformers\NumericTransformer;

class ProductMapping implements MappingInterface, NestedMappingInterface
{
    public function apply(IngestConfig $config, string $prefix = ''): IngestConfig
    {
        return $this->applyMappings($config, $prefix);
    }

    public function applyNested(NestedIngestConfig $config, string $prefix = ''): NestedIngestConfig
    {
        return $this->applyMappings($config, $prefix);
    }

    private function applyMappings(HasMappings $config, string $prefix = ''): HasMappings
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
