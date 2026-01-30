<?php

declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Enums\TransactionMode;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Tests\Fixtures\Models\User;

it('can be instantiated for a valid model', function () {
    $config = IngestConfig::for(User::class);
    expect($config)->toBeInstanceOf(IngestConfig::class)
        ->and($config->model)->toBe(User::class);
});

it('throws an exception for a non-model class', function () {
    IngestConfig::for(stdClass::class);
})->throws(InvalidConfigurationException::class);

it('can fluently build a full configuration', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['host' => 'ftp.example.com'])
        ->keyedBy('sku')
        ->onDuplicate(DuplicateStrategy::UPDATE)
        ->map('product_name', 'name')
        ->mapAndTransform('stock_level', 'stock', fn($v) => (int) $v)
        ->validate(['product_name' => 'required'])
        ->validateWithModelRules()
        ->setChunkSize(250)
        ->setDisk('s3');

    expect($config->sourceType)->toBe(SourceType::FTP)
        ->and($config->sourceOptions)->toBe(['host' => 'ftp.example.com'])
        ->and($config->keyedBy)->toBe('sku')
        ->and($config->duplicateStrategy)->toBe(DuplicateStrategy::UPDATE)
        ->and($config->mappings)->toHaveKey('product_name')
        ->and($config->mappings['stock_level']['transformer'])->toBeInstanceOf(SerializableClosure::class)
        ->and($config->validationRules)->toBe(['product_name' => 'required'])
        ->and($config->useModelRules)->toBeTrue()
        ->and($config->chunkSize)->toBe(250)
        ->and($config->disk)->toBe('s3');
});

it('correctly transforms values using closure', function () {
    $config = IngestConfig::for(User::class)
        ->mapAndTransform('name', 'name', fn($value) => strtoupper($value))
        ->map('email', 'email');

    $processor = new RowProcessor();
    $run = IngestRun::factory()->create();

    $processor->processChunk(
        $run,
        $config,
        [['number' => 1, 'data' => ['name' => 'lower', 'email' => 'test@test.de']]],
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'LOWER']);
});

it('throws an exception when relating to a non-model class', function () {
    IngestConfig::for(User::class)
        ->relate('field', 'relation', stdClass::class, 'id');
})->throws(InvalidConfigurationException::class);

it('can enable atomic transactions via atomic helper', function () {
    $config = IngestConfig::for(User::class)->atomic();
    expect($config->transactionMode)->toBe(TransactionMode::CHUNK);
});

it('can set transaction mode explicitly', function () {
    $config = IngestConfig::for(User::class)->transactionMode(TransactionMode::ROW);
    expect($config->transactionMode)->toBe(TransactionMode::ROW);
});

it('can set before and after row callbacks', function () {
    $config = IngestConfig::for(User::class)
        ->beforeRow(fn(array &$data) => $data['name'] = 'Modified')
        ->afterRow(fn($model, $data) => $model->touch());

    expect($config->beforeRowCallback)->toBeInstanceOf(SerializableClosure::class)
        ->and($config->afterRowCallback)->toBeInstanceOf(SerializableClosure::class);
});

it('can handle header aliases for mapping', function () {
    $config = IngestConfig::for(User::class)
        ->map(['user_email', 'email', 'E-Mail'], 'email');

    $normalizationMap = $config->getHeaderNormalizationMap();

    expect($normalizationMap)->toBe([
        'user_email' => 'user_email',
        'email' => 'user_email',
        'E-Mail' => 'user_email',
    ]);
});

it('can set a model resolver', function () {
    $config = IngestConfig::for(User::class)
        ->resolveModelUsing(fn(array $rowData) => User::class);

    expect($config->modelResolver)->toBeInstanceOf(SerializableClosure::class);
});

it('resolves model class using default when no resolver set', function () {
    $config = IngestConfig::for(User::class);

    $result = $config->resolveModelClass(['name' => 'Test']);

    expect($result)->toBe(User::class);
});

it('resolves model class using custom resolver', function () {
    $config = IngestConfig::for(User::class)
        ->resolveModelUsing(fn(array $rowData) => Product::class);

    $result = $config->resolveModelClass(['name' => 'Test']);

    expect($result)->toBe(Product::class);
});

it('throws exception when resolver returns non-model class', function () {
    $config = IngestConfig::for(User::class)
        ->resolveModelUsing(fn(array $rowData) => stdClass::class);

    expect(fn() => $config->resolveModelClass(['name' => 'Test']))
        ->toThrow(InvalidConfigurationException::class, "must be an instance of Illuminate\Database\Eloquent\Model");
});

it('returns null from getAttributeForKeyedBy when keyedBy does not match any mapping', function () {
    $config = IngestConfig::for(User::class)
        ->map('email', 'email')
        ->keyedBy('non_existent_field');

    expect($config->getAttributeForKeyedBy())->toBeNull();
});

it('returns null from getAttributeForKeyedBy when keyedBy is null', function () {
    $config = IngestConfig::for(User::class);
    expect($config->getAttributeForKeyedBy())->toBeNull();
});

it('returns null from getAttributeForKeyedBy when keyedBy is an empty array', function () {
    $config = IngestConfig::for(User::class);

    $reflection = new ReflectionClass($config);
    $property = $reflection->getProperty('keyedBy');
    $property->setAccessible(true);
    $property->setValue($config, []);

    expect($config->getAttributeForKeyedBy())->toBeNull();
});

it('returns correct attribute from getAttributeForKeyedBy when keyedBy matches a mapping or alias', function () {
    $config = IngestConfig::for(User::class)
        ->map(['email_address', 'email'], 'email')
        ->keyedBy('email_address');

    expect($config->getAttributeForKeyedBy())->toBe('email');

    $config->keyedBy('email');
    expect($config->getAttributeForKeyedBy())->toBe('email');
});
