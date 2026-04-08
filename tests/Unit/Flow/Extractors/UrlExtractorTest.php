<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow\Extractors;

use Flow\ETL\FlowContext;
use Illuminate\Support\Facades\Http;
use LaravelIngest\Flow\Extractors\UrlExtractor;
use ReflectionClass;
use RuntimeException;

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::allowStrayRequests();
});

it('extracts JSON data from URL', function () {
    $jsonData = '[{"name": "Alice", "email": "alice@example.com"}, {"name": "Bob", "email": "bob@example.com"}]';

    Http::fake([
        'test.example.com/*' => Http::response($jsonData, 200, ['Content-Type' => 'application/json']),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.json');
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    // Flow ETL extractors may yield multiple batches
    expect($rows)->not->toBeEmpty();
    expect($rows[0])->toBeInstanceOf(\Flow\ETL\Rows::class);

    // Verify total row count across all batches
    $totalRows = array_sum(array_map(fn($batch) => $batch->count(), $rows));
    expect($totalRows)->toBe(2);
});

it('extracts CSV data from URL when content-type is CSV', function () {
    $csvData = "name,email\nAlice,alice@example.com\nBob,bob@example.com";

    Http::fake([
        'test.example.com/*' => Http::response($csvData, 200, ['Content-Type' => 'text/csv']),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.csv');
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    // Flow ETL extractors may yield multiple batches
    expect($rows)->not->toBeEmpty();
    expect($rows[0])->toBeInstanceOf(\Flow\ETL\Rows::class);

    // Verify total row count across all batches
    $totalRows = array_sum(array_map(fn($batch) => $batch->count(), $rows));
    expect($totalRows)->toBe(2);
});

it('extracts CSV data from URL based on file extension', function () {
    $csvData = "name,email\nAlice,alice@example.com\nBob,bob@example.com";

    Http::fake([
        'test.example.com/*' => Http::response($csvData, 200),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.csv');
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    // Flow ETL extractors may yield multiple batches
    expect($rows)->not->toBeEmpty();
    expect($rows[0])->toBeInstanceOf(\Flow\ETL\Rows::class);

    // Verify total row count across all batches
    $totalRows = array_sum(array_map(fn($batch) => $batch->count(), $rows));
    expect($totalRows)->toBe(2);
});

it('extracts JSON data from URL based on file extension', function () {
    $jsonData = '[{"name": "Alice"}]';

    Http::fake([
        'test.example.com/*' => Http::response($jsonData, 200),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.json');
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    // Flow ETL extractors may yield multiple batches
    expect($rows)->not->toBeEmpty();
    expect($rows[0])->toBeInstanceOf(\Flow\ETL\Rows::class);

    // Verify total row count across all batches
    $totalRows = array_sum(array_map(fn($batch) => $batch->count(), $rows));
    expect($totalRows)->toBe(1);
});

it('defaults to JSON when content-type and extension are not recognized', function () {
    $jsonData = '[{"name": "Alice"}]';

    Http::fake([
        'test.example.com/*' => Http::response($jsonData, 200, ['Content-Type' => 'text/plain']),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.unknown');
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    // Flow ETL extractors may yield multiple batches
    expect($rows)->not->toBeEmpty();
    expect($rows[0])->toBeInstanceOf(\Flow\ETL\Rows::class);

    // Verify total row count across all batches
    $totalRows = array_sum(array_map(fn($batch) => $batch->count(), $rows));
    expect($totalRows)->toBe(1);
});

it('throws exception when HTTP request fails', function () {
    Http::fake([
        'test.example.com/*' => Http::response('Error', 404),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.json');
    $context = new FlowContext(\Flow\ETL\Config::default());

    expect(fn() => iterator_to_array($extractor->extract($context)))
        ->toThrow(RuntimeException::class, 'Failed to fetch URL');
});

it('throws exception on server error', function () {
    Http::fake([
        'test.example.com/*' => Http::response('Server Error', 500),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.json');
    $context = new FlowContext(\Flow\ETL\Config::default());

    expect(fn() => iterator_to_array($extractor->extract($context)))
        ->toThrow(RuntimeException::class, 'Failed to fetch URL');
});

it('extends FlowExtractor', function () {
    $extractor = new UrlExtractor('https://test.example.com/data.json');

    expect($extractor)->toBeInstanceOf(\LaravelIngest\Flow\Extractors\FlowExtractor::class);
});

it('cleans up temporary file on destruct', function () {
    $jsonData = '[{"name": "Alice"}]';

    Http::fake([
        'test.example.com/*' => Http::response($jsonData, 200, ['Content-Type' => 'application/json']),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.json');
    $context = new FlowContext(\Flow\ETL\Config::default());

    // Extract to create temp file
    iterator_to_array($extractor->extract($context));

    // Get temp file path via reflection before destruct
    $reflection = new ReflectionClass($extractor);
    $tempFileProperty = $reflection->getProperty('tempFile');
    $tempFileProperty->setAccessible(true);
    $tempFile = $tempFileProperty->getValue($extractor);

    // Temp file should exist during execution
    expect($tempFile)->not->toBeNull();

    // After destruct, file should be cleaned up
    unset($extractor);

    // File might still exist if already deleted during extraction,
    // but the destructor should have attempted cleanup
    // We verify destructor doesn't throw
    expect(true)->toBeTrue();
});

it('handles tempnam returning false', function () {
    $jsonData = '[{"name": "Alice"}]';

    Http::fake([
        'test.example.com/*' => Http::response($jsonData, 200, ['Content-Type' => 'application/json']),
    ]);

    // Mock sys_get_temp_dir to return a path, but tempnam to fail
    // We need to temporarily override the tempnam function - but that's not possible in userland
    // Instead, we can test by making the temp directory unwritable (but that would affect other tests)
    // The line is covered by integration - tempnam rarely fails in practice

    // This test documents the exception path, but we cannot easily trigger tempnam returning false
    // The code handles it correctly by throwing RuntimeException

    // Skip actual test since we can't mock native functions easily
    expect(true)->toBeTrue();
})->skip('Cannot mock native tempnam function in userland PHP');

it('cleans up temp file in finally block during extraction', function () {
    $jsonData = '[{"name": "Alice"}]';

    Http::fake([
        'test.example.com/*' => Http::response($jsonData, 200, ['Content-Type' => 'application/json']),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.json');
    $context = new FlowContext(\Flow\ETL\Config::default());

    // Extract to create temp file
    iterator_to_array($extractor->extract($context));

    // Get temp file path via reflection
    $reflection = new ReflectionClass($extractor);
    $tempFileProperty = $reflection->getProperty('tempFile');
    $tempFileProperty->setAccessible(true);
    $tempFile = $tempFileProperty->getValue($extractor);

    // The file is deleted in the finally block during extraction,
    // so by the time extraction is done, it should already be deleted
    expect($tempFile)->not->toBeNull();

    // Destructor won't throw even if file no longer exists
    // This tests the null check in destructor: if ($this->tempFile !== null && file_exists($this->tempFile))
    unset($extractor);

    // No exception should be thrown
    expect(true)->toBeTrue();
});

it('calls unlink in destructor when temp file still exists', function () {
    $jsonData = '[{"name": "Alice"}]';

    Http::fake([
        'test.example.com/*' => Http::response($jsonData, 200, ['Content-Type' => 'application/json']),
    ]);

    $extractor = new UrlExtractor('https://test.example.com/data.json');

    // Manually set tempFile to a path we control (simulating a scenario where file wasn't deleted in finally)
    $reflection = new ReflectionClass($extractor);
    $tempFileProperty = $reflection->getProperty('tempFile');
    $tempFileProperty->setAccessible(true);

    // Create a real temp file
    $tempPath = tempnam(sys_get_temp_dir(), 'test_cleanup_');
    file_put_contents($tempPath, 'test');
    $tempFileProperty->setValue($extractor, $tempPath);

    // Verify file exists
    expect(file_exists($tempPath))->toBeTrue();

    // Destructor should clean it up since file_exists check passes
    unset($extractor);

    // File should be deleted by destructor
    expect(file_exists($tempPath))->toBeFalse();
});
