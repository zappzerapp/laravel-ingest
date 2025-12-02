<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\UrlHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('throws exception when filesystem file is missing', function () {
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(\LaravelIngest\Enums\SourceType::FILESYSTEM, ['path' => 'missing.csv']);

    $handler = new \LaravelIngest\Sources\FilesystemHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, "We could not find the file");

it('throws exception when url download fails', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);
    Storage::fake('local');

    $config = IngestConfig::for(Product::class)
        ->fromSource(\LaravelIngest\Enums\SourceType::URL, ['url' => 'https://example.com/404.csv']);

    $handler = new UrlHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, "Failed to download file");