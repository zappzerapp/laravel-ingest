<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use LaravelIngest\IngestConfig;

/**
 * @example
 * class ProductMapping implements MappingInterface
 * {
 *     public function apply(IngestConfig $config, string $prefix = ''): IngestConfig
 *     {
 *         return $config
 *             ->map('product_id', 'id')
 *             ->map('product_name', 'name')
 *             ->mapAndTransform('price_cents', 'price', new NumericTransformer(2));
 *     }
 * }
 *
 * $config = IngestConfig::for(Order::class);
 * (new ProductMapping())->apply($config, 'line_item');
 */
interface MappingInterface
{
    public function apply(IngestConfig $config, string $prefix = ''): IngestConfig;
}
