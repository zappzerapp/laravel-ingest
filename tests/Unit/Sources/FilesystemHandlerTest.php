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
