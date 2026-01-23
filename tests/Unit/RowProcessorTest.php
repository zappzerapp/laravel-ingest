<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\Models\AdminUser;
use LaravelIngest\Tests\Fixtures\Models\Category;
use LaravelIngest\Tests\Fixtures\Models\ProductWithCategory;
use LaravelIngest\Tests\Fixtures\Models\RegularUser;
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

it('bubbles exception when using chunk transactions', function () {
    $config = IngestConfig::for(User::class)
        ->transactionMode(TransactionMode::CHUNK)
        ->map('name', 'name');

    $config->validate(['name' => 'integer']);

    $chunk = [['number' => 1, 'data' => ['name' => 'John']]];

    $this->processor->processChunk($this->run, $config, $chunk, false);
})->throws(ValidationException::class);

it('saves valid rows even if others fail in the same chunk when using row transactions', function () {
    $config = IngestConfig::for(User::class)
        ->transactionMode(TransactionMode::ROW)
        ->map('name', 'name')
        ->map('email', 'email')
        ->validate(['email' => 'required|email']);

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'Valid', 'email' => 'valid@test.com']],
        ['number' => 2, 'data' => ['name' => 'Invalid', 'email' => 'not-an-email']],
    ];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(1);
    expect($results['failed'])->toBe(1);

    $this->assertDatabaseHas('users', ['email' => 'valid@test.com']);
    $this->assertDatabaseMissing('users', ['name' => 'Invalid']);
});

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
    expect($row->errors['validation']['email'][0])->toBe('The email field must be a valid email address.');
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

it('dynamically resolves model class based on row data using resolveModelUsing', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->resolveModelUsing(fn(array $data) => str_contains($data['email'], 'admin') ? AdminUser::class : RegularUser::class);

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'Admin User', 'email' => 'admin@example.com']],
        ['number' => 2, 'data' => ['name' => 'Regular User', 'email' => 'user@example.com']],
    ];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(2);
    expect($results['failed'])->toBe(0);

    $this->assertDatabaseHas('users', [
        'email' => 'admin@example.com',
        'name' => 'Admin User',
        'is_admin' => true,
        'password' => 'admin_password',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'user@example.com',
        'name' => 'Regular User',
        'is_admin' => false,
        'password' => 'user_password',
    ]);
});

it('uses default model class when no resolver is set', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email');

    $chunk = [['number' => 1, 'data' => ['name' => 'Default User', 'email' => 'default@example.com']]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(1);

    $this->assertDatabaseHas('users', [
        'email' => 'default@example.com',
        'password' => 'password',
    ]);
});

it('throws exception when model resolver returns invalid class', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->transactionMode(TransactionMode::CHUNK)
        ->resolveModelUsing(fn(array $data) => 'InvalidClassName');

    $chunk = [['number' => 1, 'data' => ['name' => 'Test', 'email' => 'test@example.com']]];

    $this->processor->processChunk($this->run, $config, $chunk, false);
})->throws(InvalidConfigurationException::class);

it('handles duplicate detection with dynamically resolved models', function () {
    AdminUser::create(['name' => 'Existing Admin', 'email' => 'existing-admin@example.com']);

    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE)
        ->map('name', 'name')
        ->map('email', 'email')
        ->resolveModelUsing(fn(array $data) => str_contains($data['email'], 'admin') ? AdminUser::class : RegularUser::class);

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'Updated Admin', 'email' => 'existing-admin@example.com']],
    ];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(1);

    $this->assertDatabaseHas('users', [
        'email' => 'existing-admin@example.com',
        'name' => 'Updated Admin',
    ]);

    expect(User::where('email', 'existing-admin@example.com')->count())->toBe(1);
});

it('extracts values from nested data using dot notation', function () {
    $config = IngestConfig::for(User::class)
        ->map('user.profile.name', 'name')
        ->map('user.contact.email', 'email');

    $chunk = [[
        'number' => 1,
        'data' => [
            'user' => [
                'profile' => [
                    'name' => 'Nested User',
                ],
                'contact' => [
                    'email' => 'nested@example.com',
                ],
            ],
        ],
    ]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['failed'])->toBe(0);
    expect($results['successful'])->toBe(1);

    $this->assertDatabaseHas('users', [
        'name' => 'Nested User',
        'email' => 'nested@example.com',
    ]);
});

it('handles mixed flat and nested fields with dot notation', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('contact.email', 'email');

    $chunk = [[
        'number' => 1,
        'data' => [
            'name' => 'Mixed User',
            'contact' => [
                'email' => 'mixed@example.com',
            ],
        ],
    ]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(1);

    $this->assertDatabaseHas('users', [
        'name' => 'Mixed User',
        'email' => 'mixed@example.com',
    ]);
});

it('applies transformer to nested field values', function () {
    $config = IngestConfig::for(User::class)
        ->map('meta.email', 'email')
        ->mapAndTransform('meta.full_name', 'name', fn($value) => strtoupper($value));

    $chunk = [[
        'number' => 1,
        'data' => [
            'meta' => [
                'full_name' => 'john doe',
                'email' => 'john@example.com',
            ],
        ],
    ]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(1);

    $this->assertDatabaseHas('users', [
        'name' => 'JOHN DOE',
        'email' => 'john@example.com',
    ]);
});

it('skips mapping when nested path does not exist', function () {
    $config = IngestConfig::for(User::class)
        ->map('user.name', 'name')
        ->map('user.email', 'email')
        ->map('user.missing.deep.path', 'is_admin');

    $chunk = [[
        'number' => 1,
        'data' => [
            'user' => [
                'name' => 'Partial User',
                'email' => 'partial@example.com',
            ],
        ],
    ]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(1);

    $user = User::where('email', 'partial@example.com')->first();
    expect($user->name)->toBe('Partial User');
    expect($user->is_admin)->toBe(false);
});

it('resolves relations from nested source fields', function () {
    $category = Category::create(['name' => 'Electronics']);

    $config = IngestConfig::for(ProductWithCategory::class)
        ->map('product.name', 'name')
        ->relate('product.category_name', 'category', Category::class, 'name');

    $chunk = [[
        'number' => 1,
        'data' => [
            'product' => [
                'name' => 'Laptop',
                'category_name' => 'Electronics',
            ],
        ],
    ]];

    $results = $this->processor->processChunk($this->run, $config, $chunk, false);

    expect($results['successful'])->toBe(1);

    $this->assertDatabaseHas('products_with_category', [
        'name' => 'Laptop',
        'category_id' => $category->id,
    ]);
});
