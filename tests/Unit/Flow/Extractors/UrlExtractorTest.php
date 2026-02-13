<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow\Extractors;

use Flow\ETL\FlowContext;
use Illuminate\Support\Facades\Http;
use LaravelIngest\Flow\Extractors\UrlExtractor;
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
