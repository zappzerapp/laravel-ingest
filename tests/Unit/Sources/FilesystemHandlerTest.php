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

it('does not delete the source file during cleanup', function () {
    Storage::fake('local');
    $path = 'important-data.csv';
    Storage::disk('local')->put($path, 'content');

    $handler = new FilesystemHandler();

    $handler->cleanup();

    Storage::disk('local')->assertExists($path);
});

it('getProcessedFilePath returns path', function () {
    $handler = new FilesystemHandler();
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('path');
    $property->setValue($handler, 'test/path.csv');

    expect($handler->getProcessedFilePath())->toBe('test/path.csv');
});

it('does not calculate total rows to maintain stream efficiency', function () {
    Storage::fake('local');
    $content = "name,email\nJohn,john@example.com\nJane,jane@example.com";
    Storage::disk('local')->put('test.csv', $content);

    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'test.csv']);

    $handler = new FilesystemHandler();
    iterator_to_array($handler->read($config));

    expect($handler->getTotalRows())->toBeNull();
});

it('throws exception for file path outside base path', function () {
    $tempFile = sys_get_temp_dir() . '/ingest_test_security.csv';
    touch($tempFile);

    try {
        $config = IngestConfig::for(Product::class)
            ->fromSource(SourceType::FILESYSTEM, ['path' => $tempFile]);

        $handler = new FilesystemHandler();
        iterator_to_array($handler->read($config));
    } catch (SourceException $e) {
        expect($e->getMessage())->toBe('Invalid file path detected for security reasons.');

        return;
    } finally {
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    throw new Exception('Should have thrown SourceException');
});

it('resolves realpath and updates path when file exists', function () {
    $fileName = 'test_realpath_file.csv';
    $filePath = base_path($fileName);
    $content = "name,email\nJohn,john@example.com\nJane,jane@example.com";

    file_put_contents($filePath, $content);

    Storage::fake('local');

    Storage::disk('local')->put($filePath, $content);

    Storage::disk('local')->put($fileName, $content);

    try {
        $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
            ->fromSource(SourceType::FILESYSTEM, ['path' => $filePath]);

        $handler = new FilesystemHandler();

        $reflection = new ReflectionClass($handler);
        $property = $reflection->getProperty('path');

        $rows = iterator_to_array($handler->read($config));

        $finalPath = $property->getValue($handler);

        expect($finalPath)->toBe(realpath($filePath))
            ->and($finalPath)->toBeString()->not->toBeEmpty()
            ->and($rows)->toHaveCount(2);

    } finally {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
});

it('resolves realpath inside base path but fails storage check if absolute', function () {
    $file = base_path('ingest_test_valid.csv');
    touch($file);

    try {
        $config = IngestConfig::for(Product::class)
            ->fromSource(SourceType::FILESYSTEM, ['path' => 'ingest_test_valid.csv']);

        $handler = new FilesystemHandler();
        iterator_to_array($handler->read($config));
    } catch (SourceException $e) {
        expect($e->getMessage())->toContain('We could not find the file');

        return;
    } finally {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    throw new Exception('Should have thrown SourceException');
});

it('throws exception for path containing directory traversal', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => '../../../etc/passwd']);

    $handler = new FilesystemHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'Invalid file path detected for security reasons.');

it('throws exception for path with backslash directory traversal', function () {
    $config = IngestConfig::for(Product::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => '..\\..\\..\\windows\\system32']);

    $handler = new FilesystemHandler();

    iterator_to_array($handler->read($config));
})->throws(SourceException::class, 'Invalid file path detected for security reasons.');
