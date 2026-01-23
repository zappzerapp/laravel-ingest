<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\FilesystemHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('throws exception when filesystem file is missing', function () {
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'missing.csv']);

    $handler = new FilesystemHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'We could not find the file');

it('cleanup removes temporary file', function () {
    $tempPath = sys_get_temp_dir() . '/ingest-temp-test.csv';

    $handler = new FilesystemHandler();
    $result = $handler->cleanup();

    expect($result)->toBeNull();
});

it('getProcessedFilePath returns path', function () {
    $handler = new FilesystemHandler();
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('path');
    $property->setAccessible(true);
    $property->setValue($handler, 'test/path.csv');

    expect($handler->getProcessedFilePath())->toBe('test/path.csv');
});

it('getTotalRows returns total rows after read', function () {
    Storage::fake('local');
    $content = "name,email\nJohn,john@example.com\nJane,jane@example.com";
    Storage::disk('local')->put('test.csv', $content);

    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv']);

    $handler = new FilesystemHandler();
    iterator_to_array($handler->read($config));

    expect($handler->getTotalRows())->toBe(2);
});

it('getTotalRows returns null before read', function () {
    $handler = new FilesystemHandler();
    expect($handler->getTotalRows())->toBeNull();
});
