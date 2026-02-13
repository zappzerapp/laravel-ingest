<?php

declare(strict_types=1);

use Flow\ETL\Config as EtlConfig;
use Flow\ETL\FlowContext;
use Flow\ETL\Row;
use Flow\ETL\Row\Entry\IntegerEntry;
use Flow\ETL\Row\Entry\JsonEntry;
use Flow\ETL\Row\Entry\StringEntry;
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
            new JsonEntry('json_data', [
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

it('processes unmapped data into json_data column', function () {
    $config = IngestConfig::for(User::class)
        ->map('data', 'name')
        ->extraFields(fn($data) => [
            'unmapped_field' => $data['unmapped'] ?? null,
            'another_field' => $data['extra'] ?? null,
        ]);

    $ingestRun = IngestRun::factory()->create();
    $loader = new EloquentLoader($config, $ingestRun);

    $rows = new Rows(
        Row::create(
            new StringEntry('email', 'test@example.com'),
            new JsonEntry('data', ['unmapped' => 'unmapped_value', 'extra' => 123])
        )
    );

    $loader->load($rows, new FlowContext(EtlConfig::default()));

    $user = User::where('email', 'test@example.com')->first();
    expect($user->name)->toBeJson();
    expect(json_decode($user->name, true))->toHaveKey('unmapped_field', 'unmapped_value');
    expect(json_decode($user->name, true))->toHaveKey('another_field', 123);
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
