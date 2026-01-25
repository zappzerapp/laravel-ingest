<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\UrlHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('throws exception when url download fails', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://example.com/404.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'Failed to download file');

it('cleans up temporary file after url download', function () {
    Http::fake(['*' => Http::response("sku,name\nURL001,URL Product")]);
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://example.com/products.csv']);

    $handler = new UrlHandler();
    iterator_to_array($handler->read($config));

    expect($handler->getTotalRows())->toBeNull();

    $tempPath = $handler->getProcessedFilePath();
    Storage::disk('local')->assertExists($tempPath);

    $handler->cleanup();
    Storage::disk('local')->assertMissing($tempPath);
});

it('throws exception when url download has connection error', function () {
    Http::fake(fn() => throw new ConnectionException('Could not resolve host'));
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://unreachable.example.com/products.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'Failed to stream file from URL');

it('throws exception for invalid url scheme', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'ftp://example.com/file.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'URL source requires a valid http or https URL.');

it('throws exception for missing url host', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https:///file.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'URL source requires a valid http or https URL.');

it('throws exception when url host is not in allowed list', function () {
    config(['ingest_security.allowed_url_hosts' => ['allowed.com']]);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://blocked.com/file.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'URL host is not allowed.');

it('throws exception when url host is blocked', function () {
    config(['ingest_security.blocked_url_hosts' => ['blocked.com']]);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://blocked.com/file.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'URL host is blocked.');

it('throws exception for private ip addresses', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://192.168.1.1/file.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'Private or reserved IPs are not allowed for URL sources.');

it('throws exception when unable to open local stream', function () {
    Http::fake(['*' => Http::response('test,data')]);

    $diskMock = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $diskMock->shouldReceive('makeDirectory')->andReturn(true);
    // Force path to return a location that will cause fopen to fail (simulate IO error)
    $diskMock->shouldReceive('path')->andReturn('/non/existent/path/for/ingest/test.csv');

    Storage::shouldReceive('disk')->with('local')->andReturn($diskMock);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://example.com/test.csv']);

    $handler = new UrlHandler();

    // The handler wraps any exception in SourceException with the message "Failed to stream file from URL..."
    try {
        iterator_to_array($handler->read($config));
    } catch (SourceException $e) {
        expect($e->getMessage())->toContain('Failed to stream file from URL');

        return;
    }

    throw new Exception('Should have thrown SourceException');
});

it('throws exception when fopen fails to open local stream', function () {
    Http::fake(['*' => Http::response('test,data')]);
    Storage::fake('local');

    // Mock the filesystem to return a path that cannot be opened
    $diskMock = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $diskMock->shouldReceive('makeDirectory')->andReturn(true);
    $diskMock->shouldReceive('path')->andReturn('/this/path/does/not/exist/and/cannot/be/created.csv');

    Storage::shouldReceive('disk')->with('local')->andReturn($diskMock);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://example.com/test.csv']);

    $handler = new UrlHandler();

    expect(fn() => iterator_to_array($handler->read($config)))
        ->toThrow(SourceException::class, 'Failed to stream file from URL');
});

it('throws exception when fopen returns false', function () {
    Http::fake(['*' => Http::response('test,data')]);
    Storage::fake('local');

    $handler = Mockery::mock(UrlHandler::class)->makePartial();
    $handler->shouldAllowMockingProtectedMethods();
    $handler->shouldReceive('openStream')->once()->andReturn(false);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://example.com/test.csv']);

    expect(fn() => iterator_to_array($handler->read($config)))
        ->toThrow(SourceException::class, 'Failed to open local stream for URL download.');
});
