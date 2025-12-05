<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\FilesystemHandler;
use LaravelIngest\Sources\FtpHandler;
use LaravelIngest\Sources\SourceHandlerFactory;
use LaravelIngest\Sources\UploadHandler;
use LaravelIngest\Sources\UrlHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('throws exception when filesystem file is missing', function () {
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'missing.csv']);

    $handler = new FilesystemHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, "We could not find the file");

it('throws exception when url download fails', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://example.com/404.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, "Failed to download file");

it('throws exception when url option is missing', function () {
    $config = IngestConfig::for(Product::class)->fromSource(SourceType::URL);

    iterator_to_array((new UrlHandler())->read($config));

})->throws(SourceException::class, 'URL source requires a "url" option.');

it('cleans up temporary file after url download', function () {
    Http::fake(['*' => Http::response("sku,name\nURL001,URL Product")]);
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://example.com/products.csv']);

    $handler = new UrlHandler();
    iterator_to_array($handler->read($config));

    expect($handler->getTotalRows())->toBe(1);

    $tempPath = $handler->getProcessedFilePath();
    Storage::disk('local')->assertExists($tempPath);

    $handler->cleanup();
    Storage::disk('local')->assertMissing($tempPath);
});


it('throws exception if filesystem path option is missing', function () {
    $config = IngestConfig::for(Product::class)->fromSource(SourceType::FILESYSTEM, []);
    iterator_to_array((new FilesystemHandler())->read($config));
})->throws(SourceException::class, 'The filesystem source is missing the "path" option');

it('throws exception if payload is not an uploaded file', function () {
    $config = IngestConfig::for(Product::class)->fromSource(SourceType::UPLOAD);
    iterator_to_array((new UploadHandler())->read($config, 'not-a-file'));
})->throws(SourceException::class, 'UploadHandler expects an instance of UploadedFile.');

it('cleans up temporary file after upload processing', function () {
    $payload = UploadedFile::fake()->create('test.csv');
    $config = IngestConfig::for(Product::class);
    $handler = new UploadHandler();

    iterator_to_array($handler->read($config, $payload));

    $path = $handler->getProcessedFilePath();
    Storage::disk(config('ingest.disk'))->assertExists($path);

    $handler->cleanup();
    Storage::disk(config('ingest.disk'))->assertMissing($path);
});


it('throws exception when ftp stream cannot be opened', function () {
    config()->set('filesystems.disks.test_ftp', ['driver' => 'ftp']);
    Storage::fake('local');

    Storage::shouldReceive('disk')->with('test_ftp')->andReturnSelf();
    Storage::shouldReceive('exists')->with('products.csv')->andReturn(true);
    Storage::shouldReceive('readStream')->with('products.csv')->andReturn(false);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FTP, ['disk' => 'test_ftp', 'path' => 'products.csv']);

    iterator_to_array((new FtpHandler())->read($config));
})->throws(SourceException::class, "Could not open read stream for remote file");

it('throws exception for unsupported source type', function () {
    $factory = new SourceHandlerFactory();

    $reflection = new ReflectionClass($factory);
    $property = $reflection->getProperty('handlers');
    $handlers = $property->getValue($factory);
    unset($handlers[SourceType::UPLOAD->value]);
    $property->setValue($factory, $handlers);

    $factory->make(SourceType::UPLOAD);
})->throws(InvalidConfigurationException::class, "Source type 'upload' is not supported.");

it('throws exception when url download has connection error', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
    });
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://unreachable.example.com/products.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'Failed to stream file from URL');