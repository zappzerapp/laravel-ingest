<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\RemoteDiskHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

beforeEach(function () {
    Storage::fake('local');
    config(['ingest.disk' => 'local']);
});

it('cleanup removes temporary file', function () {
    Storage::fake('local');
    Storage::disk('local')->put('ingest-temp-test.csv', 'test content');

    $handler = new RemoteDiskHandler();
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('temporaryPath');
    $property->setAccessible(true);
    $property->setValue($handler, 'ingest-temp-test.csv');

    expect(Storage::disk('local')->exists('ingest-temp-test.csv'))->toBeTrue();

    $handler->cleanup();

    expect(Storage::disk('local')->exists('ingest-temp-test.csv'))->toBeFalse();
});

it('cleanup handles null temporary path', function () {
    $handler = new RemoteDiskHandler();

    expect(
        fn() => $handler->cleanup()
    )->not->toThrow(Exception::class);
});

it('getProcessedFilePath returns temporary path', function () {
    $handler = new RemoteDiskHandler();
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('temporaryPath');
    $property->setAccessible(true);
    $property->setValue($handler, 'ingest-temp/test/path.csv');

    expect($handler->getProcessedFilePath())->toBe('ingest-temp/test/path.csv');
});

it('getTotalRows returns total rows after read', function () {
    Storage::fake('local');
    $content = "name,email\nJohn,john@example.com\nJane,jane@example.com";
    Storage::disk('local')->put('remote-test.csv', $content);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'local', 'path' => 'remote-test.csv']);

    $handler = new RemoteDiskHandler();
    iterator_to_array($handler->read($config));

    expect($handler->getTotalRows())->toBe(2);
});

it('getTotalRows returns null before read', function () {
    $handler = new RemoteDiskHandler();
    expect($handler->getTotalRows())->toBeNull();
});

it('throws SourceException when disk option is missing', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['path' => 'test.csv']);

    $handler = new RemoteDiskHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(SourceException::class, 'FTP/SFTP source requires a "disk" option');
});

it('throws SourceException when path option is missing', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'local']);

    $handler = new RemoteDiskHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(SourceException::class, 'FTP/SFTP source requires a "path" option');
});

it('throws SourceException when file not found on remote', function () {
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'local', 'path' => 'nonexistent.csv']);

    $handler = new RemoteDiskHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(SourceException::class, 'File not found at remote path');
});

it('wraps general exceptions in SourceException', function () {
    Storage::fake('local');
    Storage::disk('local')->put('corrupt.csv', 'test');

    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturnSelf();
    Storage::shouldReceive('exists')
        ->with('corrupt.csv')
        ->andReturn(true);
    Storage::shouldReceive('readStream')
        ->with('corrupt.csv')
        ->andReturn(null);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'local', 'path' => 'corrupt.csv']);

    $handler = new RemoteDiskHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(SourceException::class, 'Could not open read stream');
});

it('wraps non-SourceException throwables in SourceException with original message', function () {
    Storage::fake('local');

    Storage::shouldReceive('disk')
        ->with('remote-disk')
        ->andReturnSelf();
    Storage::shouldReceive('exists')
        ->with('test.csv')
        ->andReturn(true);
    Storage::shouldReceive('readStream')
        ->with('test.csv')
        ->andThrow(new RuntimeException('Simulated connection failure'));

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'remote-disk', 'path' => 'test.csv']);

    $handler = new RemoteDiskHandler();

    expect(
        fn() => iterator_to_array($handler->read($config))
    )->toThrow(SourceException::class, 'Failed to read from remote source: Simulated connection failure');
});
