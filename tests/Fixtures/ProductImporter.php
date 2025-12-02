<?php

namespace LaravelIngest\Tests\Fixtures;

use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\Product;

class ProductImporter implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for(Product::class)
            ->fromSource(SourceType::FILESYSTEM, ['path' => 'products.csv'])
            ->keyedBy('product_sku')
            ->onDuplicate(DuplicateStrategy::UPDATE)
            ->map('product_sku', 'sku')
            ->map('product_name', 'name')
            ->map('quantity', 'stock');
    }
}