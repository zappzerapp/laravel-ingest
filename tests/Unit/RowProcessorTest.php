<?php

use Illuminate\Validation\ValidationException;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\Models\Category;
use LaravelIngest\Tests\Fixtures\Models\ProductWithCategory;
use LaravelIngest\Tests\Fixtures\Models\User;

beforeEach(function () {
    $this->processor = new RowProcessor();
    $this->run = IngestRun::factory()->create();
});

it('successfully processes a valid row', function () {
    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->map('name', 'name')
        ->map('email', 'email');

    $rowData = ['name' => 'John Doe', 'email' => 'john@example.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    $this->assertDatabaseHas('users', ['email' => 'john@example.com', 'name' => 'John Doe']);
});

it('throws a validation exception for an invalid row', function () {
    $config = IngestConfig::for(User::class)->validate(['email' => 'required|email']);
    $rowData = ['email' => 'not-an-email'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['failed'])->toBe(1);
    expect($results['successful'])->toBe(0);

    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $this->run->id,
        'row_number' => 1,
        'status' => 'failed'
    ]);
});

it('updates a duplicate row when strategy is update', function () {
    $user = User::create(['name' => 'Old Name', 'email' => 'jane@example.com']);
    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE)
        ->map('name', 'name')
        ->map('email', 'email');

    $rowData = ['name' => 'Jane Doe Updated', 'email' => 'jane@example.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Jane Doe Updated']);
});

it('skips a duplicate row when strategy is skip', function () {
    User::create(['name' => 'Old Name', 'email' => 'skip@example.com']);
    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::SKIP)
        ->map('name', 'name')
        ->map('email', 'email');

    $rowData = ['name' => 'New Name', 'email' => 'skip@example.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    $user = User::where('email', 'skip@example.com')->first();
    expect($user->name)->toBe('Old Name');
});

it('does not persist data on a dry run', function () {
    $config = IngestConfig::for(User::class)->map('name', 'name')->map('email', 'email');
    $rowData = ['name' => 'Dry Run User', 'email' => 'dry@run.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk($this->run, $config, $chunk, true);

    $this->assertDatabaseMissing('users', ['email' => 'dry@run.com']);
});

it('logs an error row when duplicate strategy is fail', function () {
    User::create(['email' => 'duplicate@example.com', 'name' => 'Original']);

    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::FAIL)
        ->map('email', 'email');

    $results = $this->processor->processChunk(
        $this->run,
        $config,
        [['number' => 1, 'data' => ['email' => 'duplicate@example.com']]],
        false
    );

    expect($results['failed'])->toBe(1);

    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $this->run->id,
        'status' => 'failed',
    ]);

    expect(User::first()->name)->toBe('Original');
});


it('rolls back transaction on failure when atomic is enabled', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->validate(['email' => 'email'])
        ->atomic();

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'Valid User', 'email' => 'valid@test.com']],
        ['number' => 2, 'data' => ['name' => 'Invalid User', 'email' => 'invalid-email']],
    ];

    $this->expectException(ValidationException::class);

    try {
        $this->processor->processChunk($this->run, $config, $chunk, false);
    } finally {
        $this->assertDatabaseMissing('users', ['email' => 'valid@test.com']);
    }
});

it('handles relations when source values are empty', function () {
    Category::create(['name' => 'Electronics']);

    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('cat_name', 'category', Category::class, 'name');

    $chunk = [
        ['number' => 1, 'data' => ['product_name' => 'iPhone', 'cat_name' => null]],
        ['number' => 2, 'data' => ['product_name' => 'Samsung', 'cat_name' => '']],
    ];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    $this->assertDatabaseHas('products_with_category', ['name' => 'iPhone', 'category_id' => null]);
    $this->assertDatabaseHas('products_with_category', ['name' => 'Samsung', 'category_id' => null]);
});

it('skips mappings and relations if source field does not exist in data', function () {
    Category::create(['name' => 'Books']);
    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->map('product_stock', 'stock')
        ->relate('category_name', 'category', Category::class, 'name');

    $chunk = [['number' => 1, 'data' => ['product_name' => 'The Lord of the Rings']]];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'The Lord of the Rings',
        'category_id' => null
    ]);
});

it('merges model rules and custom rules for validation', function () {
    $config = IngestConfig::for(User::class)
        ->validate(['name' => 'min:10'])
        ->validateWithModelRules();

    $chunk = [['number' => 1, 'data' => ['name' => 'John Doe Is Long Enough', 'email' => 'not-an-email']]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['failed'])->toBe(1);
    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $this->run->id,
        'status' => 'failed',
    ]);
});