<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\Sources\UploadHandler;
use LaravelIngest\Tests\Fixtures\Models\Product;

beforeEach(function () {
    Storage::fake('local');
    config(['ingest.disk' => 'local']);
});

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
    Storage::disk('local')->assertExists($path);

    $handler->cleanup();
    Storage::disk('local')->assertMissing($path);
});

it('throws exception when file size exceeds limit', function () {
    $payload = UploadedFile::fake()->create('large.csv', 51 * 1024);

    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::UPLOAD, ['max_size_mb' => 50 * 1024 * 1024]);

    $handler = new UploadHandler();

    expect(fn() => iterator_to_array($handler->read($config, $payload)))
        ->toThrow(SourceException::class, 'File size exceeds maximum allowed size');
});

it('throws exception for disallowed mime type', function () {

    $tmpFile = sys_get_temp_dir() . '/test.bin';
    file_put_contents($tmpFile, random_bytes(100));

    $payload = new UploadedFile(
        $tmpFile,
        'test.bin',
        'application/pdf',
        null,
        true
    );

    $config = IngestConfig::for(Product::class);
    $handler = new UploadHandler();

    try {
        iterator_to_array($handler->read($config, $payload));
    } catch (SourceException $e) {
        @unlink($tmpFile);
        expect($e->getMessage())->toContain("File type 'application/octet-stream' is not allowed");

        return;
    }

    @unlink($tmpFile);
    throw new Exception('Should have thrown SourceException');
});
