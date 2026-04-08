<?php

declare(strict_types=1);

use Flow\ETL\Config as EtlConfig;
use Flow\ETL\FlowContext;
use Flow\ETL\Row;
use Flow\ETL\Row\Entry\IntegerEntry;
use Flow\ETL\Row\Entry\JsonEntry;
use Flow\ETL\Rows;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Flow\Loaders\EloquentLoader;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\Models\User;

it('implements Flow ETL Loader interface', function () {
    $config = IngestConfig::for(User::class);
    $ingestRun = IngestRun::factory()->create();

    $loader = new EloquentLoader($config, $ingestRun);

    expect($loader)->toBeInstanceOf(Flow\ETL\Loader::class);
});

it('creates new models with UPSERT strategy when no key specified', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->onDuplicate(DuplicateStrategy::UPSERT);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);
});

it('updates existing models with UPDATE strategy', function () {
    User::create(['email' => 'test@example.com', 'name' => 'Old Name']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Updated Name'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Updated Name',
    ]);
});

it('skips existing models with SKIP strategy', function () {
    User::create(['email' => 'test@example.com', 'name' => 'Existing']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::SKIP);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', [
                'email' => 'test@example.com',
                'unmapped_field' => 'unmapped_value',
                'another_field' => 123,
            ])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Existing',
    ]);
});

it('upserts models with UPSERT strategy', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->map('password', 'password')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPSERT);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows1 = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'First', 'password' => 'secret'])
        )
    );

    $loader->load($rows1, new FlowContext(EtlConfig::default()));

    $rows2 = new Rows(
        Row::create(
            new IntegerEntry('number', 2),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Updated', 'password' => 'secret'])
        )
    );

    $loader->load($rows2, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Updated',
    ]);
});

it('wraps entire load in transaction with CHUNK mode', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->transactionMode(TransactionMode::CHUNK);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test1@example.com', 'name' => 'User 1'])
        ),
        Row::create(
            new IntegerEntry('number', 2),
            new JsonEntry('data', ['email' => 'test2@example.com', 'name' => 'User 2'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', ['email' => 'test1@example.com']);
    $this->assertDatabaseHas('users', ['email' => 'test2@example.com']);
});

it('skips persistence in dry run mode', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun, isDryRun: true);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseMissing('users', [
        'email' => 'test@example.com',
    ]);
});

it('updates if newer with UPDATE_IF_NEWER strategy', function () {
    $oldUser = User::create(['email' => 'test@example.com', 'name' => 'Old Name', 'updated_at' => '2024-01-01 00:00:00']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamps('updated_at', 'updated_at');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', [
                'email' => 'test@example.com',
                'name' => 'Updated Name',
                'updated_at' => '2024-12-01 00:00:00',
            ])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Updated Name',
    ]);
});

it('skips update when source is older with UPDATE_IF_NEWER strategy', function () {
    User::create(['email' => 'test@example.com', 'name' => 'Existing Name', 'updated_at' => '2024-12-01 00:00:00']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamps('updated_at', 'updated_at');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', [
                'email' => 'test@example.com',
                'name' => 'Should Not Update',
                'updated_at' => '2024-01-01 00:00:00',
            ])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Existing Name',
    ]);
});

it('calls after row callback', function () {
    $callbackCalled = false;
    $callbackModel = null;
    $callbackData = null;

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->afterRow(function ($model, $data) use (&$callbackCalled, &$callbackModel, &$callbackData) {
            $callbackCalled = true;
            $callbackModel = $model;
            $callbackData = $data;
        });

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    expect($callbackCalled)->toBeTrue();
    expect($callbackModel)->toBeInstanceOf(User::class);
    expect($callbackData)->toHaveKey('email', 'test@example.com');
});

it('handles validation errors and logs them', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->validate(['email' => 'required|email']);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'invalid-email'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    // Row should be logged as failed
    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $ingestRun->id,
        'row_number' => 1,
        'status' => 'failed',
    ]);
});

it('handles empty rows gracefully', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows();

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    // Should complete without error
    expect(true)->toBeTrue();
});

it('wraps each row in transaction with ROW mode', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->transactionMode(TransactionMode::ROW);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test1@example.com', 'name' => 'User 1'])
        ),
        Row::create(
            new IntegerEntry('number', 2),
            new JsonEntry('data', ['email' => 'test2@example.com', 'name' => 'User 2'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', ['email' => 'test1@example.com']);
    $this->assertDatabaseHas('users', ['email' => 'test2@example.com']);
});

it('rolls back entire chunk when one row fails in CHUNK mode', function () {
    // Create first user to cause duplicate key error
    User::create(['email' => 'existing@example.com', 'name' => 'Existing']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::FAIL)
        ->transactionMode(TransactionMode::CHUNK);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'new@example.com', 'name' => 'New User'])
        ),
        Row::create(
            new IntegerEntry('number', 2),
            new JsonEntry('data', ['email' => 'existing@example.com', 'name' => 'Duplicate'])
        )
    );

    // Should throw exception for duplicate
    expect(fn() => $loader->load($rows, new FlowContext(EtlConfig::default())))
        ->toThrow(RuntimeException::class);

    // Neither row should be committed due to rollback
    $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
});

it('creates models without keyedBy using UPSERT strategy', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->onDuplicate(DuplicateStrategy::UPSERT);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);
});

it('processes extraFields callback for additional model attributes', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->extraFields(fn($data) => [
            'is_admin' => $data['is_admin'] ?? false,
        ]);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', [
                'email' => 'test@example.com',
                'name' => 'Test User',
                'is_admin' => true,
            ])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
        'is_admin' => true,
    ]);
});

it('calls after chunk callback', function () {
    $callbackCalled = false;
    $callbackModels = null;
    $callbackRun = null;

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->afterChunk(function ($models, $ingestRun) use (&$callbackCalled, &$callbackModels, &$callbackRun) {
            $callbackCalled = true;
            $callbackModels = $models;
            $callbackRun = $ingestRun;
        });

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    expect($callbackCalled)->toBeTrue();
    expect($callbackModels)->toHaveCount(1);
    expect($callbackRun)->toBe($ingestRun);
});

it('calls before save callback', function () {
    $callbackCalled = false;
    $callbackModel = null;
    $callbackData = null;

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->beforeSave(function ($model, $data) use (&$callbackCalled, &$callbackModel, &$callbackData) {
            $callbackCalled = true;
            $callbackModel = $model;
            $callbackData = $data;

            return $model;
        });

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    expect($callbackCalled)->toBeTrue();
    expect($callbackModel)->toBeInstanceOf(User::class);
    expect($callbackData)->toHaveKey('email', 'test@example.com');
});

it('can modify model in before save callback', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->beforeSave(function ($model, $data) {
            $model->name = 'Modified Name';

            return $model;
        });

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Original Name'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Modified Name',
    ]);
});

it('throws exception when before save returns non-model', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->beforeSave(fn($model, $data) => 'not a model');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    expect(fn() => $loader->load($rows, new FlowContext(EtlConfig::default())))
        ->toThrow(RuntimeException::class, 'beforeSave callback must return an Eloquent model');
});

it('calls after chunk callback with multiple rows', function () {
    $callbackCalled = false;
    $callbackModels = null;

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->afterChunk(function ($models) use (&$callbackCalled, &$callbackModels) {
            $callbackCalled = true;
            $callbackModels = $models;
        });

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    // Process multiple rows to trigger chunk callback
    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'user1@example.com', 'name' => 'User 1'])
        ),
        Row::create(
            new IntegerEntry('number', 2),
            new JsonEntry('data', ['email' => 'user2@example.com', 'name' => 'User 2'])
        ),
        Row::create(
            new IntegerEntry('number', 3),
            new JsonEntry('data', ['email' => 'user3@example.com', 'name' => 'User 3'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    expect($callbackCalled)->toBeTrue();
    expect($callbackModels)->toHaveCount(3);
    expect($callbackModels[0])->toBeInstanceOf(User::class);
});

it('handles exception in after chunk callback', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->afterChunk(function ($models) {
            throw new RuntimeException('Chunk processing failed');
        });

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    expect(fn() => $loader->load($rows, new FlowContext(EtlConfig::default())))
        ->toThrow(RuntimeException::class, 'Chunk processing failed');
});

it('auto-increments row number when number field is missing', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    // Create rows without IntegerEntry('number', ...) - uses plain array
    $rows = new Rows(
        Row::create(
            new JsonEntry('data', ['email' => 'user1@example.com', 'name' => 'User 1'])
        ),
        Row::create(
            new JsonEntry('data', ['email' => 'user2@example.com', 'name' => 'User 2'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', ['email' => 'user1@example.com']);
    $this->assertDatabaseHas('users', ['email' => 'user2@example.com']);
});

it('handles extraFields with non-existent database column gracefully', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->extraFields(fn($data) => [
            'nonexistent_column' => 'value',
            'is_admin' => true,
        ]);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    // Should not throw - nonexistent_column should be filtered out
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
        'is_admin' => true,
    ]);
});

it('handles nested source keys in mappings', function () {
    $config = IngestConfig::for(User::class)
        ->map('user.email', 'email')
        ->map('user.name', 'name');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['user' => ['email' => 'test@example.com', 'name' => 'Test User']])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);
});

it('shows array keys in FAIL strategy error message', function () {
    User::create(['email' => 'test@example.com', 'name' => 'Existing']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy(['email', 'name'])
        ->onDuplicate(DuplicateStrategy::FAIL);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Existing'])
        )
    );

    expect(fn() => $loader->load($rows, new FlowContext(EtlConfig::default())))
        ->toThrow(RuntimeException::class, "Duplicate entry found for key 'email, name'.");
});

it('handles updateIfNewer when source column is missing', function () {
    User::create(['email' => 'test@example.com', 'name' => 'Existing', 'updated_at' => '2024-12-01 00:00:00']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamps('updated_at', 'updated_at');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    // Row without updated_at in data - should not update
    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'New Name'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    // Name should NOT have changed (no timestamp comparison available)
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Existing',
    ]);
});

it('updates when db timestamp is null', function () {
    // Create user without updated_at
    $user = User::create(['email' => 'test@example.com', 'name' => 'Test', 'updated_at' => null]);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
        ->compareTimestamps('updated_at', 'updated_at');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', [
                'email' => 'test@example.com',
                'name' => 'Updated Name',
                'updated_at' => '2024-01-01 00:00:00',
            ])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    // Should update because db timestamp is null
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Updated Name',
    ]);
});

it('finds existing model with multiple keys', function () {
    User::create(['email' => 'test@example.com', 'name' => 'Existing']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->keyedBy(['email', 'name'])
        ->onDuplicate(DuplicateStrategy::SKIP);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Existing'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    // Should find existing and skip
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Existing',
    ]);
    expect(User::count())->toBe(1);
});

it('handles missing key in model data when searching', function () {
    // Create a user
    User::create(['email' => 'test@example.com', 'name' => 'Existing']);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    // Row with a new email - should create new model since key not found
    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'new@example.com', 'name' => 'New User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    // Should create a new user
    expect(User::count())->toBe(2);
    $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
});

it('uses model validation rules when configured', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->validateWithModelRules();

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'valid@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('users', [
        'email' => 'valid@example.com',
        'name' => 'Test User',
    ]);
});

it('upserts when model does not use timestamps', function () {
    // SimpleItem doesn't use timestamps - test the branches in upsertModel
    $config = IngestConfig::for(LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->map('code', 'code')
        ->keyedBy('code')
        ->onDuplicate(DuplicateStrategy::UPSERT);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['code' => 'ITEM001'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('simple_items', ['code' => 'ITEM001']);
});

it('throws runtime exception in testing mode for non-model beforeSave callback', function () {
    // This tests line 125-126 in EloquentLoader (testing mode only)
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->beforeSave(fn($model, $data) => 'not a model');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test'])
        )
    );

    // In testing mode, should throw RuntimeException
    expect(fn() => $loader->load($rows, new FlowContext(EtlConfig::default())))
        ->toThrow(RuntimeException::class, 'beforeSave callback must return an Eloquent model');
});

it('syncs many-to-many relations when cache has values', function () {
    $adminRole = LaravelIngest\Tests\Fixtures\Models\Role::create(['name' => 'Admin', 'slug' => 'admin']);
    $editorRole = LaravelIngest\Tests\Fixtures\Models\Role::create(['name' => 'Editor', 'slug' => 'editor']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', LaravelIngest\Tests\Fixtures\Models\Role::class, 'slug', ',');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => 'admin,editor'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $user = User::where('email', 'john@test.com')->first();
    expect($user->roles)->toHaveCount(2)
        ->and($user->roles->pluck('slug')->toArray())->toContain('admin', 'editor');
});

it('skips syncing when relation value not found in cache', function () {
    // Create roles
    LaravelIngest\Tests\Fixtures\Models\Role::create(['name' => 'Admin', 'slug' => 'admin']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', LaravelIngest\Tests\Fixtures\Models\Role::class, 'slug', ',');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    // Role 'nonexistent' doesn't exist in database, so it won't be in the prefetch cache
    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => 'nonexistent,admin'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $user = User::where('email', 'john@test.com')->first();
    // Should only sync 'admin' role, 'nonexistent' should be skipped
    expect($user->roles)->toHaveCount(1)
        ->and($user->roles->first()->slug)->toBe('admin');
});

it('logs rows when ingest.log_rows is enabled', function () {
    config(['ingest.log_rows' => true]);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name');

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'test@example.com', 'name' => 'Test User'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $ingestRun->id,
        'row_number' => 1,
        'status' => 'success',
    ]);

    config(['ingest.log_rows' => false]);
});

it('logs failed rows with validation errors', function () {
    config(['ingest.log_rows' => true]);

    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->map('name', 'name')
        ->validate(['email' => 'required|email']);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new IntegerEntry('number', 1),
            new JsonEntry('data', ['email' => 'invalid-email', 'name' => 'Test'])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $ingestRun->id,
        'row_number' => 1,
        'status' => 'failed',
    ]);

    config(['ingest.log_rows' => false]);
});
