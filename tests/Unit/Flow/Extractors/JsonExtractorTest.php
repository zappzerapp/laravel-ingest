<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow\Extractors;

use Exception;
use Flow\ETL\Config;
use Flow\ETL\FlowContext;
use Illuminate\Filesystem\Filesystem;
use LaravelIngest\Flow\Extractors\JsonExtractor;

beforeEach(function () {
    $this->filesystem = new Filesystem();
});

afterEach(function () {
    // Cleanup any temp files
    if (isset($this->tempFile) && file_exists($this->tempFile)) {
        unlink($this->tempFile);
    }
});

it('can extract data from JSON file', function () {
    $this->tempFile = tempnam(sys_get_temp_dir(), 'test_json_');
    $jsonData = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
        ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
    ];
    file_put_contents($this->tempFile, json_encode($jsonData));

    $extractor = new JsonExtractor($this->tempFile);
    $context = new FlowContext(Config::default());

    $results = [];
    foreach ($extractor->extract($context) as $rows) {
        foreach ($rows as $row) {
            $results[] = $row;
        }
    }

    expect($results)->toHaveCount(3);
});

it('can extract data from JSON file with nested objects', function () {
    $this->tempFile = tempnam(sys_get_temp_dir(), 'test_json_');
    $jsonData = [
        ['id' => 1, 'user' => ['name' => 'Alice', 'email' => 'alice@example.com']],
        ['id' => 2, 'user' => ['name' => 'Bob', 'email' => 'bob@example.com']],
    ];
    file_put_contents($this->tempFile, json_encode($jsonData));

    $extractor = new JsonExtractor($this->tempFile);
    $context = new FlowContext(Config::default());

    $results = [];
    foreach ($extractor->extract($context) as $rows) {
        foreach ($rows as $row) {
            $results[] = $row;
        }
    }

    expect($results)->toHaveCount(2);
});

it('handles empty JSON array', function () {
    $this->tempFile = tempnam(sys_get_temp_dir(), 'test_json_');
    file_put_contents($this->tempFile, json_encode([]));

    $extractor = new JsonExtractor($this->tempFile);
    $context = new FlowContext(Config::default());

    $results = [];
    foreach ($extractor->extract($context) as $rows) {
        foreach ($rows as $row) {
            $results[] = $row;
        }
    }

    expect($results)->toBeEmpty();
});

it('handles JSON with primitive values', function () {
    $this->tempFile = tempnam(sys_get_temp_dir(), 'test_json_');
    $jsonData = [
        ['id' => 1, 'active' => true, 'count' => 42, 'price' => 19.99],
        ['id' => 2, 'active' => false, 'count' => 0, 'price' => 0.0],
    ];
    file_put_contents($this->tempFile, json_encode($jsonData));

    $extractor = new JsonExtractor($this->tempFile);
    $context = new FlowContext(Config::default());

    $results = [];
    foreach ($extractor->extract($context) as $rows) {
        foreach ($rows as $row) {
            $results[] = $row;
        }
    }

    expect($results)->toHaveCount(2);
});

it('throws exception for non-existent file', function () {
    $extractor = new JsonExtractor('/non/existent/file.json');
    $context = new FlowContext(Config::default());

    // FlowJsonExtractor throws RuntimeException on invalid file
    expect(fn() => iterator_to_array($extractor->extract($context)))->toThrow(Exception::class);
});

it('throws exception for invalid JSON', function () {
    $this->tempFile = tempnam(sys_get_temp_dir(), 'test_json_');
    file_put_contents($this->tempFile, 'not valid json {{');

    $extractor = new JsonExtractor($this->tempFile);
    $context = new FlowContext(Config::default());

    expect(fn() => iterator_to_array($extractor->extract($context)))->toThrow(Exception::class);
});

it('can extract single JSON object', function () {
    $this->tempFile = tempnam(sys_get_temp_dir(), 'test_json_');
    $jsonData = ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'];
    file_put_contents($this->tempFile, json_encode($jsonData));

    $extractor = new JsonExtractor($this->tempFile);
    $context = new FlowContext(Config::default());

    $results = [];
    foreach ($extractor->extract($context) as $rows) {
        foreach ($rows as $row) {
            $results[] = $row;
        }
    }

    // Flow JSON extractor treats each field of a single object as a row entry
    expect($results)->toHaveCount(3);
});
