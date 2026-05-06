<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use LaravelIngest\NestedIngestConfig;

/**
 * Interface for mapping configurations that can be applied to nested configs.
 *
 * This extends MappingInterface for use inside `nest()` blocks. Implementations
 * can be reused across both top-level and nested configurations.
 *
 * @example
 * class ProductMapping implements MappingInterface, NestedMappingInterface
 * {
 *     public function apply(IngestConfig $config, string $prefix = ''): IngestConfig
 *     {
 *         return $this->applyMappings($config, $prefix);
 *     }
 *
 *     public function applyNested(NestedIngestConfig $config, string $prefix = ''): NestedIngestConfig
 *     {
 *         return $this->applyMappings($config, $prefix);
 *     }
 *
 *     private function applyMappings(HasMappings $config, string $prefix = ''): HasMappings
 *     {
 *         return $config
 *             ->map("{$prefix}product_id", 'id')
 *             ->map("{$prefix}product_name", 'name');
 *     }
 * }
 */
interface NestedMappingInterface
{
    /**
     * @param  NestedIngestConfig  $config  The nested config to apply mappings to
     * @param  string  $prefix  Optional prefix for source field names
     * @return NestedIngestConfig The modified config (for chaining)
     */
    public function applyNested(NestedIngestConfig $config, string $prefix = ''): NestedIngestConfig;
}
