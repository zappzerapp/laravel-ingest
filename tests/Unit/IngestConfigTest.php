<?php

use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Tests\Fixtures\Models\User;

it('can be instantiated for a valid model', function () {
    $config = IngestConfig::for(User::class);
    expect($config)->toBeInstanceOf(IngestConfig::class);
    expect($config->model)->toBe(User::class);
});

it('throws an exception for a non-model class', function () {
    IngestConfig::for(\stdClass::class);
})->throws(InvalidConfigurationException::class);

it('can fluently build a full configuration', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['host' => 'ftp.example.com'])
        ->keyedBy('sku')
        ->onDuplicate(DuplicateStrategy::UPDATE)
        ->map('product_name', 'name')
        ->mapAndTransform('stock_level', 'stock', fn($v) => (int)$v)
        ->validate(['product_name' => 'required'])
        ->validateWithModelRules()
        ->setChunkSize(250)
        ->setDisk('s3');

    expect($config->sourceType)->toBe(SourceType::FTP);
    expect($config->sourceOptions)->toBe(['host' => 'ftp.example.com']);
    expect($config->keyedBy)->toBe('sku');
    expect($config->duplicateStrategy)->toBe(DuplicateStrategy::UPDATE);
    expect($config->mappings)->toHaveKey('product_name');
    expect($config->mappings['stock_level']['transformer'])->toBeInstanceOf(SerializableClosure::class);
    expect($config->validationRules)->toBe(['product_name' => 'required']);
    expect($config->useModelRules)->toBeTrue();
    expect($config->chunkSize)->toBe(250);
    expect($config->disk)->toBe('s3');
});

it('correctly transforms values using closure', function () {
    $config = IngestConfig::for(User::class)
        ->mapAndTransform('name', 'name', function($value) {
            return strtoupper($value);
        })
        ->map('email', 'email');

    $processor = new \LaravelIngest\Services\RowProcessor();
    $run = \LaravelIngest\Models\IngestRun::factory()->create();

    $processor->processChunk(
        $run,
        $config,
        [['number' => 1, 'data' => ['name' => 'lower', 'email' => 'test@test.de']]],
        false
    );

    $this->assertDatabaseHas('users', ['name' => 'LOWER']);
});