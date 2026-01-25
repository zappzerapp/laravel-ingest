<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Events\RowProcessed;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\Models\Category;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Tests\Fixtures\Models\ProductWithCategory;
use LaravelIngest\Tests\Fixtures\Models\User;
use LaravelIngest\Tests\Fixtures\Models\UserWithRules;

it('executes afterRow callback after successful row processing', function () {
    $callbackExecuted = false;
    $callbackModel = null;

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->afterRow(function ($model, $data) use (&$callbackExecuted, &$callbackModel) {
            $callbackExecuted = true;
            $callbackModel = $model;
        });

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect($callbackExecuted)->toBeTrue()
        ->and($callbackModel)->toBeInstanceOf(User::class);
});

it('uses ROW transaction mode', function () {
    $transactionStarted = false;

    DB::listen(function ($query) use (&$transactionStarted) {
        if (str_contains($query->sql, 'BEGIN') || str_contains($query->sql, 'SAVEPOINT')) {
            $transactionStarted = true;
        }
    });

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->transactionMode(TransactionMode::ROW);

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['email' => 'john@test.com']);
});

it('handles errors and continues processing other rows', function () {
    Event::fake([RowProcessed::class]);
    config(['ingest.log_rows' => true]);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->validate(['email' => 'required|email']);

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'John', 'email' => 'invalid-email']],
        ['number' => 2, 'data' => ['name' => 'Jane', 'email' => 'jane@test.com']],
    ];

    $result = (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect($result['failed'])->toBe(1)
        ->and($result['successful'])->toBe(1);

    Event::assertDispatched(RowProcessed::class, fn($event) => $event->status === 'failed');
});

it('throws exception on error in CHUNK transaction mode', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->validate(['email' => 'required|email'])
        ->transactionMode(TransactionMode::CHUNK);

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'invalid']]];

    expect(fn() => (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    ))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('uses CHUNK transaction mode for entire chunk', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->transactionMode(TransactionMode::CHUNK);

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com']],
        ['number' => 2, 'data' => ['name' => 'Jane', 'email' => 'jane@test.com']],
    ];

    $result = (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect($result['successful'])->toBe(2);
    $this->assertDatabaseHas('users', ['email' => 'john@test.com']);
    $this->assertDatabaseHas('users', ['email' => 'jane@test.com']);
});

it('uses model validation rules when useModelRules is enabled', function () {
    $config = IngestConfig::for(UserWithRules::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->validateWithModelRules();

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'invalid-email']]];

    $result = (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect($result['failed'])->toBe(1);
});

it('skips mapping when source field is missing', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->map('optional_field', 'some_attribute');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'John', 'email' => 'john@test.com']);
});

it('handles nested key mappings', function () {
    $config = IngestConfig::for(User::class)
        ->map('user.name', 'name')
        ->map('user.email', 'email');

    $chunk = [['number' => 1, 'data' => ['user' => ['name' => 'John', 'email' => 'john@test.com']]]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'John', 'email' => 'john@test.com']);
});

it('returns false for hasNestedKey when nested path does not exist', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('user.details.email', 'email');

    $chunk = [['number' => 1, 'data' => ['user' => ['other' => 'value'], 'name' => 'John', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'John', 'email' => 'john@test.com']);
});

it('includes unmapped fillable fields in model data', function () {
    $config = IngestConfig::for(User::class)
        ->map('full_name', 'name');

    $chunk = [['number' => 1, 'data' => ['full_name' => 'John', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'John', 'email' => 'john@test.com']);
});

it('uses SKIP duplicate strategy', function () {
    User::create(['name' => 'Original', 'email' => 'john@test.com']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::SKIP);

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Original', 'email' => 'john@test.com']);
    expect(User::count())->toBe(1);
});

it('uses UPDATE duplicate strategy', function () {
    User::create(['name' => 'Original', 'email' => 'john@test.com']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE);

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Updated', 'email' => 'john@test.com']);
    expect(User::count())->toBe(1);
});

it('uses FAIL duplicate strategy', function () {
    User::create(['name' => 'Original', 'email' => 'john@test.com']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::FAIL);

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com']]];

    $result = (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect($result['failed'])->toBe(1);
});

it('uses UPDATE_IF_NEWER duplicate strategy and updates when newer', function () {
    $user = User::create(['name' => 'Original', 'email' => 'john@test.com']);
    $user->updated_at = now()->subDay();
    $user->save();

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->mapAndTransform('import_date', 'updated_at', fn($v) => $v)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('updated_at', 'updated_at');

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com', 'import_date' => now()->toDateTimeString()]]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Updated', 'email' => 'john@test.com']);
});

it('uses UPDATE_IF_NEWER duplicate strategy and skips when older', function () {
    $user = User::create(['name' => 'Original', 'email' => 'john@test.com']);
    $user->updated_at = now();
    $user->save();

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->mapAndTransform('import_date', 'updated_at', fn($v) => $v)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('updated_at', 'updated_at');

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com', 'import_date' => now()->subWeek()->toDateTimeString()]]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Original', 'email' => 'john@test.com']);
});

it('updates when db timestamp is null in UPDATE_IF_NEWER strategy', function () {
    $user = User::create(['name' => 'Original', 'email' => 'john@test.com']);
    DB::table('users')->where('id', $user->id)->update(['updated_at' => null]);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->mapAndTransform('import_date', 'updated_at', fn($v) => $v)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('updated_at', 'updated_at');

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com', 'import_date' => now()->toDateTimeString()]]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Updated', 'email' => 'john@test.com']);
});

it('returns false from shouldUpdate when timestamp comparison not configured', function () {
    $user = User::create(['name' => 'Original', 'email' => 'john@test.com']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER);

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Original', 'email' => 'john@test.com']);
});

it('returns false from shouldUpdate when source column not in data', function () {
    $user = User::create(['name' => 'Original', 'email' => 'john@test.com']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('import_date', 'updated_at');

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Original', 'email' => 'john@test.com']);
});

it('compares DateTimeInterface timestamps correctly', function () {
    $user = User::create(['name' => 'Original', 'email' => 'john@test.com']);
    $user->updated_at = now()->subDay();
    $user->save();

    $newerDate = now();

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->mapAndTransform('source_updated_at', 'updated_at', fn($v) => $v)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('updated_at', 'updated_at');

    $chunk = [['number' => 1, 'data' => ['name' => 'Updated', 'email' => 'john@test.com', 'source_updated_at' => $newerDate]]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'Updated', 'email' => 'john@test.com']);
});

it('compares mixed timestamps with source as DateTimeInterface and db as string', function () {
    $product = Product::create(['sku' => 'TEST-001', 'name' => 'Original', 'stock' => 10, 'last_modified' => '2020-01-01 00:00:00']);

    $config = IngestConfig::for(Product::class)
        ->map('sku', 'sku')
        ->map('name', 'name')
        ->map('stock', 'stock')
        ->mapAndTransform('source_modified', 'last_modified', fn($v) => $v)
        ->keyedBy('sku')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('last_modified', 'last_modified');

    $chunk = [['number' => 1, 'data' => ['sku' => 'TEST-001', 'name' => 'Updated', 'stock' => 20, 'source_modified' => now()]]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('products', ['sku' => 'TEST-001', 'name' => 'Updated', 'stock' => 20]);
});

it('compares string timestamps using strtotime for both source and db', function () {
    $product = Product::create(['sku' => 'TEST-002', 'name' => 'Original', 'stock' => 10, 'last_modified' => '2020-01-01 00:00:00']);

    $config = IngestConfig::for(Product::class)
        ->map('sku', 'sku')
        ->map('name', 'name')
        ->map('stock', 'stock')
        ->mapAndTransform('source_modified', 'last_modified', fn($v) => $v)
        ->keyedBy('sku')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('last_modified', 'last_modified');

    $chunk = [['number' => 1, 'data' => ['sku' => 'TEST-002', 'name' => 'Updated', 'stock' => 20, 'source_modified' => '2025-01-01 00:00:00']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('products', ['sku' => 'TEST-002', 'name' => 'Updated', 'stock' => 20]);
});

it('formats validation errors correctly', function () {
    Event::fake([RowProcessed::class]);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->validate(['email' => 'required|email']);

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'invalid']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    Event::assertDispatched(RowProcessed::class, fn($event) => isset($event->errors['validation']) && isset($event->errors['message']));
});

it('extracts top level keys from nested mappings', function () {
    $config = IngestConfig::for(User::class)
        ->map('contact.name', 'name')
        ->map('contact.email', 'email');

    $chunk = [[
        'number' => 1,
        'data' => [
            'contact' => ['name' => 'John', 'email' => 'john@test.com'],
        ],
    ]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'John', 'email' => 'john@test.com']);
});

it('extracts top level keys from nested relation mappings', function () {
    $category = Category::create(['name' => 'Electronics']);

    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product_name', 'name')
        ->relate('meta.category_name', 'category', Category::class, 'name');

    $chunk = [[
        'number' => 1,
        'data' => [
            'product_name' => 'iPhone',
            'meta' => ['category_name' => 'Electronics'],
        ],
    ]];

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

it('returns false from shouldUpdate when timestamp parsing fails', function () {
    $product = Product::create(['sku' => 'TEST-INVALID-DATE', 'name' => 'Original', 'stock' => 10, 'last_modified' => '2020-01-01 00:00:00']);

    $config = IngestConfig::for(Product::class)
        ->map('sku', 'sku')
        ->map('name', 'name')
        ->map('stock', 'stock')
        ->mapAndTransform('source_modified', 'last_modified', fn($v) => $v)
        ->keyedBy('sku')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamp('last_modified', 'last_modified');

    $chunk = [['number' => 1, 'data' => ['sku' => 'TEST-INVALID-DATE', 'name' => 'Updated', 'stock' => 20, 'source_modified' => 'invalid-date-string']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('products', ['sku' => 'TEST-INVALID-DATE', 'name' => 'Original', 'stock' => 10]);
});
