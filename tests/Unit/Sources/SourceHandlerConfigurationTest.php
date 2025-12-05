<?php

use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\FilesystemHandler;
use LaravelIngest\Sources\FtpHandler;
use LaravelIngest\Sources\UrlHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('throws an exception if a required source option is missing', function ($handlerClass, $sourceType, $options) {
    $config = IngestConfig::for(Product::class)
        ->fromSource($sourceType, $options);

    iterator_to_array((new $handlerClass())->read($config));

})->with([
    'URL handler missing url' => [UrlHandler::class, SourceType::URL, []],
    'Filesystem handler missing path' => [FilesystemHandler::class, SourceType::FILESYSTEM, []],
    'FTP handler missing disk' => [FtpHandler::class, SourceType::FTP, ['path' => '...']],
    'FTP handler missing path' => [FtpHandler::class, SourceType::FTP, ['disk' => '...']],
])->throws(SourceException::class);