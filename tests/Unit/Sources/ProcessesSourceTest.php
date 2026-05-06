<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\FilesystemHandler;
use LaravelIngest\Tests\Fixtures\Models\Category;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Tests\Fixtures\Models\ProductWithCategory;

it('checks many-to-many relation columns in strict header validation', function () {
    Storage::fake('local');
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('product_name', 'name')
        ->map('quantity', 'stock')
        ->relateMany('tag_slugs', 'tags', '\LaravelIngest\Tests\Fixtures\Models\Category', 'name', ',')
        ->strictHeaders(true);

    $handler = new FilesystemHandler();

    Storage::disk('local')->put('test.csv', "product_name,quantity\nTest Product,50");

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, 'tag_slugs');
});

it('validates alias headers and finds match via alias', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "full_name,E-Mail\nJohn Doe,john@example.com");

    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('full_name', 'name')
        ->map(['user_email', 'E-Mail'], 'email')
        ->strictHeaders(true);

    $handler = new FilesystemHandler();
    $rows = iterator_to_array($handler->read($config));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['user_email'])->toBe('john@example.com');
});

it('throws exception when mapping column not found with strict headers', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "name,email\nJohn,john@example.com");

    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('full_name', 'name')
        ->map('user_email', 'email')
        ->strictHeaders(true);

    $handler = new FilesystemHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, "None of the required columns ['full_name'] were found");
});

it('throws exception when relation column not found with strict headers', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "product_name\nTest Product");

    $config = IngestConfig::for(ProductWithCategory::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('product_name', 'name')
        ->relate('category_name', 'category', Category::class, 'name')
        ->strictHeaders(true);

    $handler = new FilesystemHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, "The column 'category_name' was not found");
});

it('passes strict header validation when alias is found in translation map values', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "full_name,email_backup\nJohn Doe,john@example.com");

    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('full_name', 'name')
        ->map(['primary_email', 'email_backup'], 'email')
        ->map('email_backup', 'backup_email_attr')
        ->strictHeaders(true);

    $handler = new FilesystemHandler();
    $rows = iterator_to_array($handler->read($config));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email_backup'])->toBe('john@example.com');
});

it('includes relation source field in getHeaderNormalizationMap', function () {
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name');

    $map = $config->getHeaderNormalizationMap();

    expect($map)->toHaveKey('cat_name')
        ->and($map['cat_name'])->toBe('cat_name');
});

it('passes header validation when relation source field is used in keyedBy', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "cat_name,product_name\nElectronics,iPhone");

    $config = IngestConfig::for(ProductWithCategory::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name')
        ->keyedBy('cat_name');

    $handler = new FilesystemHandler();
    $rows = iterator_to_array($handler->read($config));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['cat_name'])->toBe('Electronics');
});

it('throws SourceException when relation source field in keyedBy is missing from CSV', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "product_name\niPhone");

    $config = IngestConfig::for(ProductWithCategory::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name')
        ->keyedBy('cat_name');

    $handler = new FilesystemHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, "key column 'cat_name'");
});

it('passes header validation with composite keyedBy containing relation and mapping', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "cat_name,sku\nElectronics,SKU-001");

    $config = IngestConfig::for(ProductWithCategory::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('sku', 'sku')
        ->relate('cat_name', 'category', Category::class, 'name')
        ->keyedBy(['cat_name', 'sku']);

    $handler = new FilesystemHandler();
    $rows = iterator_to_array($handler->read($config));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['cat_name'])->toBe('Electronics')
        ->and($rows[0]['sku'])->toBe('SKU-001');
});

it('throws SourceException when composite keyedBy is missing relation column', function () {
    Storage::fake('local');
    Storage::disk('local')->put('test.csv', "sku\nSKU-001");

    $config = IngestConfig::for(ProductWithCategory::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv'])
        ->map('sku', 'sku')
        ->relate('cat_name', 'category', Category::class, 'name')
        ->keyedBy(['cat_name', 'sku']);

    $handler = new FilesystemHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, "key column 'cat_name'");
});
