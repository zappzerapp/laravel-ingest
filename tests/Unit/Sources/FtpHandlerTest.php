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
    Storage::fake('local');
    Storage::fake('test_ftp');

    $csvData = "sku,name\nFTP001,Test Product";
    Storage::disk('test_ftp')->put('products.csv', $csvData);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, [
            'disk' => 'test_ftp',
            'path' => 'products.csv',
        ]);

    $handler = new RemoteDiskHandler();
    $generator = $handler->read($config);

    $rows = iterator_to_array($generator);

    expect($handler->getTotalRows())->toBeNull()
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['sku'])->toBe('FTP001');

    $handler->cleanup();

    $processedFilePath = $handler->getProcessedFilePath();
    expect(file_exists($processedFilePath))->toBeFalse();
});

it('throws exception if ftp file does not exist', function () {
    config()->set('filesystems.disks.test_ftp', ['driver' => 'local']);
    Storage::fake('test_ftp');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'test_ftp', 'path' => 'missing.csv']);

    expect(
        fn() => iterator_to_array((new RemoteDiskHandler())->read($config))
    )->toThrow(SourceException::class);
});

it('throws exception when ftp stream cannot be opened', function () {
    config()->set('filesystems.disks.test_ftp', ['driver' => 'local']);
    Storage::fake('local');

    Storage::fake('test_ftp');
    Storage::disk('test_ftp')->put('products.csv', 'test content');

    Storage::shouldReceive('disk')->with('test_ftp')->andReturnSelf();
    Storage::shouldReceive('exists')->with('products.csv')->andReturn(true);
    Storage::shouldReceive('readStream')->with('products.csv')->andReturn(null);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'test_ftp', 'path' => 'products.csv']);

    iterator_to_array((new RemoteDiskHandler())->read($config));
})->throws(SourceException::class, 'Could not open read stream for remote file');
