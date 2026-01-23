<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\User;

beforeEach(function () {
    Storage::fake('local');
});

it('reads json file and yields rows', function () {
    $filePath = Storage::disk('local')->path('test.json');
    $jsonData = [
        ['name' => 'John Doe', 'email' => 'john@example.com'],
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
    ];
    file_put_contents($filePath, json_encode($jsonData));

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::JSON);

    $handler = new LaravelIngest\Sources\JsonHandler();
    $rows = iterator_to_array($handler->read($config, $filePath));

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBe(['name' => 'John Doe', 'email' => 'john@example.com']);
    expect($rows[1])->toBe(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
});

it('skips non-array rows in json file', function () {
    $filePath = Storage::disk('local')->path('test.json');
    $jsonData = [
        ['name' => 'John Doe', 'email' => 'john@example.com'],
        'not an array',
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
    ];
    file_put_contents($filePath, json_encode($jsonData));

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::JSON);

    $handler = new LaravelIngest\Sources\JsonHandler();
    $rows = iterator_to_array($handler->read($config, $filePath));

    expect($rows)->toHaveCount(2);
});

it('returns null for totalRows', function () {
    $handler = new LaravelIngest\Sources\JsonHandler();
    expect($handler->getTotalRows())->toBeNull();
});

it('returns processed file path', function () {
    $handler = new LaravelIngest\Sources\JsonHandler();
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('processedFilePath');
    $property->setAccessible(true);
    $property->setValue($handler, '/path/to/test.json');

    expect($handler->getProcessedFilePath())->toBe('/path/to/test.json');
});

it('cleanup removes temp file', function () {
    $tempPath = sys_get_temp_dir() . '/ingest-temp-clean.' . bin2hex(random_bytes(8)) . '.json';
    file_put_contents($tempPath, 'test content');

    $handler = new LaravelIngest\Sources\JsonHandler();
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('tempFilePath');
    $property->setAccessible(true);
    $property->setValue($handler, $tempPath);

    expect(file_exists($tempPath))->toBeTrue();

    $handler->cleanup();

    expect(file_exists($tempPath))->toBeFalse();

    @unlink($tempPath);
});

it('cleanup does nothing when no temp file', function () {
    $handler = new LaravelIngest\Sources\JsonHandler();
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('tempFilePath');
    $property->setAccessible(true);
    $property->setValue($handler, null);

    expect(
        fn() => $handler->cleanup()
    )->not->toThrow(Exception::class);
});

it('throws exception when file cannot be read', function () {
    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::JSON);

    $handler = new LaravelIngest\Sources\JsonHandler();

    expect(
        fn() => iterator_to_array($handler->read($config, '/nonexistent/path/file.json'))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, 'Unable to read JSON file from path');
});

it('throws exception when json is invalid', function () {
    Storage::fake('local');
    $filePath = Storage::disk('local')->path('invalid.json');
    file_put_contents($filePath, '{ invalid json }');

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::JSON);

    $handler = new LaravelIngest\Sources\JsonHandler();

    expect(
        fn() => iterator_to_array($handler->read($config, $filePath))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, 'Invalid JSON');
});

it('throws exception when payload is not a string', function () {
    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::JSON);

    $handler = new LaravelIngest\Sources\JsonHandler();

    expect(
        fn() => iterator_to_array($handler->read($config, null))
    )->toThrow(LaravelIngest\Exceptions\SourceException::class, 'JsonHandler expects a valid file path');
});
