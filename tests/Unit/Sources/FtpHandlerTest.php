<?php

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\FtpHandler;
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
            'path' => 'products.csv'
        ]);

    $handler = new FtpHandler();
    $generator = $handler->read($config);

    $rows = iterator_to_array($generator);

    expect($handler->getTotalRows())->toBe(1);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['sku'])->toBe('FTP001');

    $handler->cleanup();
    Storage::disk('local')->assertMissing($handler->getProcessedFilePath());
});

it('throws exception if ftp disk option is missing', function () {
    $config = IngestConfig::for(Product::class)->fromSource(SourceType::FTP, ['path' => '...']);
    iterator_to_array((new FtpHandler())->read($config));
})->throws(SourceException::class, 'FTP/SFTP source requires a "disk" option');

it('throws exception if ftp path option is missing', function () {
    $config = IngestConfig::for(Product::class)->fromSource(SourceType::FTP, ['disk' => 'test_ftp']);
    iterator_to_array((new FtpHandler())->read($config));
})->throws(SourceException::class, 'FTP/SFTP source requires a "path" option');

it('throws exception if ftp file does not exist', function () {
    $config = IngestConfig::for(Product::class)->fromSource(SourceType::FTP, ['disk' => 'test_ftp', 'path' => 'missing.csv']);
    iterator_to_array((new FtpHandler())->read($config));
})->throws(SourceException::class, "File not found at remote path 'missing.csv'");
