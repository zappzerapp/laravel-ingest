<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\Models\Category;
use LaravelIngest\Tests\Fixtures\Models\ProductWithCategory;
use LaravelIngest\Tests\Fixtures\Models\User;
use LaravelIngest\Tests\Fixtures\Models\UserWithRules;

beforeEach(function () {
    $this->processor = new RowProcessor();
    $this->run = IngestRun::factory()->create();
});

it('successfully processes a valid row', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email');

    $rowData = ['name' => 'John Doe', 'email' => 'john@example.com', 'password' => 'secret'];
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
        'status' => 'failed',
    ]);
});

it('updates a duplicate row when strategy is update', function () {
    $user = User::create(['name' => 'Old Name', 'email' => 'jane@example.com', 'password' => 'secret']);
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
    User::create(['name' => 'Old Name', 'email' => 'skip@example.com', 'password' => 'secret']);
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

it('logs an error row when duplicate strategy is fail', function () {
    User::create(['email' => 'duplicate@example.com', 'name' => 'Original', 'password' => 'secret']);

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

it('executes beforeRow and afterRow callbacks during processing', function () {
    $callbackExecuted = false;
    $afterCallbackExecuted = false;

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->beforeRow(function (array &$data) use (&$callbackExecuted) {
            $callbackExecuted = true;
            $data['name'] = 'Modified ' . $data['name'];
        })
        ->afterRow(function ($model, array $data) use (&$afterCallbackExecuted) {
            $afterCallbackExecuted = true;
        });

    $rowData = ['name' => 'John Doe', 'email' => 'john@example.com', 'password' => 'secret'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($callbackExecuted)->toBeTrue();
    expect($afterCallbackExecuted)->toBeTrue();

    $this->assertDatabaseHas('users', [
        'email' => 'john@example.com',
        'name' => 'Modified John Doe',
    ]);
});

it('bubbles exception when using atomic transactions', function () {
    $config = IngestConfig::for(User::class)
        ->atomic()
        ->map('name', 'name');

    $config->validate(['name' => 'integer']);

    $chunk = [['number' => 1, 'data' => ['name' => 'John']]];

    $this->processor->processChunk($this->run, $config, $chunk, false);
})->throws(ValidationException::class);

it('does not log rows when config option is disabled', function () {
    Config::set('ingest.log_rows', false);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email');

    $chunk = [['number' => 1, 'data' => ['name' => 'No Log', 'email' => 'nolog@test.com']]];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    $this->assertDatabaseHas('users', ['email' => 'nolog@test.com']);
    $this->assertDatabaseCount('ingest_rows', 0);
});

it('merges validation rules from model', function () {
    $config = IngestConfig::for(UserWithRules::class)
        ->validateWithModelRules()
        ->map('email', 'email');

    $chunk = [['number' => 1, 'data' => ['email' => 'not-an-email']]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['failed'])->toBe(1);

    $row = IngestRow::first();
    expect($row->errors)->toContain('The email field must be a valid email address.');
});

it('ignores missing source fields during mapping and relation resolution', function () {
    $category = Category::create(['name' => 'Tech']);

    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('unknown_col', 'name')
        ->map('existing_col', 'name')
        ->relate('unknown_rel', 'category', Category::class, 'name');

    $chunk = [[
        'number' => 1,
        'data' => [
            'existing_col' => 'My Product',
        ],
    ]];

    $this->processor->processChunk($this->run, $config, $chunk, false);

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'My Product',
        'category_id' => null,
    ]);
});
