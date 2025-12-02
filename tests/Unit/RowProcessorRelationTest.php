<?php

use LaravelIngest\IngestConfig;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\Models\Category;
use LaravelIngest\Tests\Fixtures\Models\ProductWithCategory;

it('resolves a belongsTo relation via lookup key', function () {
    $category = Category::create(['name' => 'Electronics']);

    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name');

    $chunk = [['number' => 1, 'data' => ['product_name' => 'iPhone', 'cat_name' => 'Electronics']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'iPhone',
        'category_id' => $category->id
    ]);
});

it('leaves foreign key null if relation not found', function () {
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name');

    $chunk = [['number' => 1, 'data' => ['product_name' => 'Samsung', 'cat_name' => 'Unknown']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Samsung',
        'category_id' => null
    ]);
});