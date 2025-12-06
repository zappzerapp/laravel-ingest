<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\RemoteDiskHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

beforeEach(function () {
    config()->set('filesystems.disks.test_ftp', [
        'driver' => 'ftp',
        'host' => 'ftp',
        'username' => 'testuser',
        'password' => 'testpass',
        'root' => '/ftp/testuser',
    ]);

    Storage::fake('local');
});

it('can read a file from an ftp source', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, [
            'disk' => 'test_ftp',
            'path' => 'products.csv',
        ]);

    $handler = new RemoteDiskHandler();
    $generator = $handler->read($config);

    $rows = iterator_to_array($generator);

    expect($handler->getTotalRows())->toBe(1);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['sku'])->toBe('FTP001');

    $handler->cleanup();
    Storage::disk('local')->assertMissing($handler->getProcessedFilePath());
});

it('throws exception if ftp file does not exist', function () {
    $config = IngestConfig::for(Product::class)->fromSource(SourceType::FTP, ['disk' => 'test_ftp', 'path' => 'missing.csv']);
    iterator_to_array((new RemoteDiskHandler())->read($config));
})->throws(SourceException::class, "File not found at remote path 'missing.csv'");

it('throws exception when ftp stream cannot be opened', function () {
    config()->set('filesystems.disks.test_ftp', ['driver' => 'ftp']);
    Storage::fake('local');

    Storage::shouldReceive('disk')->with('test_ftp')->andReturnSelf();
    Storage::shouldReceive('exists')->with('products.csv')->andReturn(true);
    Storage::shouldReceive('readStream')->with('products.csv')->andReturn(null);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'test_ftp', 'path' => 'products.csv']);

    iterator_to_array((new RemoteDiskHandler())->read($config));
})->throws(SourceException::class, 'Could not open read stream for remote file');
