<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use LaravelIngest\IngestConfig;

/**
 * Interface for reusable mapping configurations.
 *
 * Mapping classes implementing this interface can be composed into
 * IngestConfig instances, allowing mappings to be shared across
 * multiple importers without duplication.
 *
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
 * // Usage:
 * $config = IngestConfig::for(Order::class);
 * (new ProductMapping())->apply($config, 'line_item');
 */
interface MappingInterface
{
    /**
     * @param  IngestConfig  $config  The config to apply mappings to
     * @param  string  $prefix  Optional prefix for source field names (e.g., 'line_item' for 'line_item_product_id')
     * @return IngestConfig The modified config (for chaining)
     */
    public function apply(IngestConfig $config, string $prefix = ''): IngestConfig;
}
