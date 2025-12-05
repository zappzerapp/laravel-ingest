<?php


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
})->throws(SourceException::class, "Failed to download file");

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

it('throws exception when url download has connection error', function () {
    Http::fake(fn() => throw new ConnectionException('Could not resolve host'));
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::URL, ['url' => 'https://unreachable.example.com/products.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'Failed to stream file from URL');