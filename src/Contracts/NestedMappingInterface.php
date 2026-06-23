<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use LaravelIngest\NestedIngestConfig;

/**
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
    public function applyNested(NestedIngestConfig $config, string $prefix = ''): NestedIngestConfig;
}
