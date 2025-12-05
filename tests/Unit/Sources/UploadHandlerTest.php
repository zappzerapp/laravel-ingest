<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\UploadHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

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