<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow\Extractors;

use Flow\ETL\FlowContext;
use Illuminate\Support\Facades\Schema;
use LaravelIngest\Flow\Extractors\DatabaseExtractor;
use LaravelIngest\Tests\Fixtures\Models\SimpleItem;

beforeEach(function () {
    config(['database.default' => 'testing']);

    Schema::dropIfExists('simple_items');
    Schema::create('simple_items', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('simple_items');
});

it('extracts data from Eloquent model query', function () {
    // Create test data
    SimpleItem::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    SimpleItem::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $extractor = new DatabaseExtractor(new SimpleItem());
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBeInstanceOf(\Flow\ETL\Rows::class);
    expect($rows[0]->count())->toBe(2);
});

it('extracts data from Eloquent query builder', function () {
    SimpleItem::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    SimpleItem::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    SimpleItem::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);

    $query = SimpleItem::query()->where('name', '!=', 'Charlie');
    $extractor = new DatabaseExtractor($query);
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    expect($rows)->toHaveCount(1);
    expect($rows[0]->count())->toBe(2);
});

it('chunks large datasets into multiple batches', function () {
    // Create 5 items
    for ($i = 1; $i <= 5; $i++) {
        SimpleItem::create(['name' => "Item {$i}"]);
    }

    $extractor = new DatabaseExtractor(new SimpleItem(), chunkSize: 2);
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    // Should yield 3 batches (2 + 2 + 1)
    expect($rows)->toHaveCount(3);
});

it('returns empty generator when no records exist', function () {
    $extractor = new DatabaseExtractor(new SimpleItem());
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    expect($rows)->toHaveCount(0);
});

it('stops extraction when STOP signal is received', function () {
    for ($i = 1; $i <= 10; $i++) {
        SimpleItem::create(['name' => "Item {$i}"]);
    }

    $extractor = new DatabaseExtractor(new SimpleItem(), chunkSize: 2);
    $context = new FlowContext(\Flow\ETL\Config::default());

    $generator = $extractor->extract($context);

    // Get first batch
    $generator->current();

    // Send STOP signal
    $generator->send(\Flow\ETL\Extractor\Signal::STOP);

    // Should stop after receiving signal
    expect($generator->valid())->toBeFalse();
});

it('converts models to arrays before creating rows', function () {
    SimpleItem::create(['name' => 'Alice', 'email' => 'alice@example.com']);

    $extractor = new DatabaseExtractor(new SimpleItem());
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    expect($rows)->toHaveCount(1);
    expect($rows[0]->count())->toBe(1);

    $entries = $rows[0]->first()->entries();
    expect($entries)->toHaveKey('name');
    expect($entries)->toHaveKey('email');
});

it('extends FlowExtractor', function () {
    $extractor = new DatabaseExtractor(new SimpleItem());

    expect($extractor)->toBeInstanceOf(\LaravelIngest\Flow\Extractors\FlowExtractor::class);
});
