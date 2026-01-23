<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
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
        'category_id' => $category->id,
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
        'category_id' => null,
    ]);
});

it('creates missing relation when createIfMissing is enabled', function () {
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name', createIfMissing: true);

    $chunk = [['number' => 1, 'data' => ['product_name' => 'iPhone', 'cat_name' => 'New Category']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('categories', ['name' => 'New Category']);

    $category = Category::where('name', 'New Category')->first();
    $this->assertDatabaseHas('products_with_category', [
        'name' => 'iPhone',
        'category_id' => $category->id,
    ]);
});

it('reuses existing relation instead of creating duplicate when createIfMissing is enabled', function () {
    $existingCategory = Category::create(['name' => 'Electronics']);

    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name', createIfMissing: true);

    $chunk = [['number' => 1, 'data' => ['product_name' => 'Laptop', 'cat_name' => 'Electronics']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect(Category::where('name', 'Electronics')->count())->toBe(1);

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Laptop',
        'category_id' => $existingCategory->id,
    ]);
});

it('caches newly created relations for subsequent rows in same chunk', function () {
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name', createIfMissing: true);

    $chunk = [
        ['number' => 1, 'data' => ['product_name' => 'iPhone', 'cat_name' => 'Mobile Devices']],
        ['number' => 2, 'data' => ['product_name' => 'Samsung Galaxy', 'cat_name' => 'Mobile Devices']],
    ];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect(Category::where('name', 'Mobile Devices')->count())->toBe(1);

    $category = Category::where('name', 'Mobile Devices')->first();
    $this->assertDatabaseHas('products_with_category', [
        'name' => 'iPhone',
        'category_id' => $category->id,
    ]);
    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Samsung Galaxy',
        'category_id' => $category->id,
    ]);
});

it('does not create missing relations during dry run', function () {
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name', createIfMissing: true);

    $chunk = [['number' => 1, 'data' => ['product_name' => 'Test Product', 'cat_name' => 'Dry Run Category']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        true
    );

    $this->assertDatabaseMissing('categories', ['name' => 'Dry Run Category']);

    $this->assertDatabaseMissing('products_with_category', ['name' => 'Test Product']);
});

it('handles multiple relations with mixed createIfMissing settings', function () {
    $existingCategory = Category::create(['name' => 'Existing']);

    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name', createIfMissing: true);

    $chunk = [
        ['number' => 1, 'data' => ['product_name' => 'Product A', 'cat_name' => 'Existing']],
        ['number' => 2, 'data' => ['product_name' => 'Product B', 'cat_name' => 'Brand New']],
    ];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect(Category::where('name', 'Existing')->count())->toBe(1);

    $this->assertDatabaseHas('categories', ['name' => 'Brand New']);

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Product A',
        'category_id' => $existingCategory->id,
    ]);
    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Product B',
        'category_id' => Category::where('name', 'Brand New')->first()->id,
    ]);
});

it('initializes relation cache when source field has no prefetched values', function () {
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name', createIfMissing: true);

    $chunk = [
        ['number' => 1, 'data' => ['product_name' => 'Product A', 'cat_name' => 'Category A']],
        ['number' => 2, 'data' => ['product_name' => 'Product B', 'cat_name' => 'Category B']],
        ['number' => 3, 'data' => ['product_name' => 'Product C', 'cat_name' => 'Category A']],
    ];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect(Category::count())->toBe(2);
    $this->assertDatabaseHas('categories', ['name' => 'Category A']);
    $this->assertDatabaseHas('categories', ['name' => 'Category B']);

    $categoryA = Category::where('name', 'Category A')->first();
    $categoryB = Category::where('name', 'Category B')->first();

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Product A',
        'category_id' => $categoryA->id,
    ]);
    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Product B',
        'category_id' => $categoryB->id,
    ]);
    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Product C',
        'category_id' => $categoryA->id,
    ]);
});

it('initializes relation cache when beforeRow callback adds relation value', function () {
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name', createIfMissing: true)
        ->beforeRow(function (array &$data) {
            if ($data['product_name'] === 'Product A') {
                $data['cat_name'] = 'Injected Category';
            }
        });

    $chunk = [
        ['number' => 1, 'data' => ['product_name' => 'Product A', 'cat_name' => null]],
        ['number' => 2, 'data' => ['product_name' => 'Product B', 'cat_name' => null]],
    ];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect(Category::count())->toBe(1);
    $this->assertDatabaseHas('categories', ['name' => 'Injected Category']);

    $category = Category::where('name', 'Injected Category')->first();

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Product A',
        'category_id' => $category->id,
    ]);
    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Product B',
        'category_id' => null,
    ]);
});
