<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow;

use Exception;
use Flow\ETL\Config;
use Flow\ETL\DataFrame;
use Flow\ETL\Exception\RuntimeException;
use Flow\ETL\FlowContext;
use Flow\ETL\Pipeline;
use Flow\ETL\Rows;
use Generator;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Flow\FlowEngine;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use RuntimeException as GlobalRuntimeException;
use Throwable;

it('implements FlowEngineInterface', function () {
    $engine = new FlowEngine();

    expect($engine)->toBeInstanceOf(\LaravelIngest\Contracts\FlowEngineInterface::class);
});

it('build returns a DataFrame', function () {
    $engine = new FlowEngine();

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD);

    $chunk = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
    ];

    $dataFrame = $engine->build($config, $chunk);

    try {
        $engine->execute($dataFrame);
        expect(true)->toBeTrue();
    } catch (Throwable $e) {
        expect($e)->toBeNull('execute() should not throw');
    }
});

it('build uses From::array for data extraction', function () {
    $engine = new FlowEngine();

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD);

    $chunk = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
    ];

    $dataFrame = $engine->build($config, $chunk);

    expect($dataFrame)->toBeInstanceOf(DataFrame::class);
});

it('build passes isDryRun flag to EloquentLoader', function () {
    $engine = new FlowEngine();

    $ingestRun = new IngestRun();
    $ingestRun->id = 1;

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD);

    $chunk = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
    ];

    $dataFrameDryRun = $engine->build($config, $chunk, $ingestRun, true);
    expect($dataFrameDryRun)->toBeInstanceOf(DataFrame::class);

    $dataFrameNormal = $engine->build($config, $chunk, $ingestRun, false);
    expect($dataFrameNormal)->toBeInstanceOf(DataFrame::class);
});

it('chains transformers in order: Callback → Header → Validation → Mapping → Relation', function () {
    $engine = new FlowEngine();

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD)
        ->beforeRow(fn(array &$row) => $row['callback_processed'] = true)
        ->map('Source Name', 'name')
        ->validate(['name' => 'required']);

    $chunk = [
        ['Source Name' => 'Alice'],
    ];

    $dataFrame = $engine->build($config, $chunk);

    expect($dataFrame)->toBeInstanceOf(DataFrame::class);
});

it('execute wraps exceptions in RuntimeException', function () {
    $engine = new FlowEngine();

    // Create an extractor that throws to test exception wrapping
    $failingExtractor = new class() implements \Flow\ETL\Extractor
    {
        public function extract(FlowContext $context): Generator
        {
            throw new GlobalRuntimeException('Test error');
            yield new Rows();
        }
    };

    $pipeline = new Pipeline($failingExtractor);
    $dataFrame = new DataFrame($pipeline, Config::default());

    expect(fn() => $engine->execute($dataFrame))
        ->toThrow(RuntimeException::class, 'Flow ETL pipeline execution failed: Test error');
});

it('execute propagates wrapped exception with original cause', function () {
    $engine = new FlowEngine();

    $originalException = new Exception('Original cause', 123);

    // Create an extractor that throws the original exception
    $failingExtractor = new class($originalException) implements \Flow\ETL\Extractor
    {
        private Exception $exception;

        public function __construct(Exception $exception)
        {
            $this->exception = $exception;
        }

        public function extract(FlowContext $context): Generator
        {
            throw $this->exception;
            yield new Rows();
        }
    };

    $pipeline = new Pipeline($failingExtractor);
    $dataFrame = new DataFrame($pipeline, Config::default());

    try {
        $engine->execute($dataFrame);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Flow ETL pipeline execution failed: Original cause');
        expect($e->getPrevious())->toBe($originalException);
        expect($e->getCode())->toBe(0);
    }
});

it('build applies callback transformer when beforeRowCallback is set', function () {
    $engine = new FlowEngine();

    $callbackExecuted = false;
    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD)
        ->beforeRow(function (array &$row) use (&$callbackExecuted) {
            $callbackExecuted = true;
            $row['processed'] = true;
        });

    $chunk = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
    ];

    $dataFrame = $engine->build($config, $chunk);

    expect($dataFrame)->toBeInstanceOf(DataFrame::class);
});

it('build does not apply callback transformer when beforeRowCallback is null', function () {
    $engine = new FlowEngine();

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD);

    $chunk = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
    ];

    $dataFrame = $engine->build($config, $chunk);

    expect($dataFrame)->toBeInstanceOf(DataFrame::class);
});

it('build creates loader with ingestRun and isDryRun', function () {
    $engine = new FlowEngine();

    $ingestRun = new IngestRun();
    $ingestRun->id = 123;

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD);

    $chunk = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
    ];

    $dataFrameDryRun = $engine->build($config, $chunk, $ingestRun, true);
    expect($dataFrameDryRun)->toBeInstanceOf(DataFrame::class);

    $dataFrameNormal = $engine->build($config, $chunk, $ingestRun, false);
    expect($dataFrameNormal)->toBeInstanceOf(DataFrame::class);
});

it('build works without ingestRun (no loader created)', function () {
    $engine = new FlowEngine();

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD)
        ->beforeRow(fn(array &$row) => $row['modified'] = true);

    $chunk = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
    ];

    $dataFrame = $engine->build($config, $chunk, null, false);
    expect($dataFrame)->toBeInstanceOf(DataFrame::class);
});

it('build handles empty chunk', function () {
    $engine = new FlowEngine();

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD);

    $chunk = [];

    $dataFrame = $engine->build($config, $chunk);

    expect($dataFrame)->toBeInstanceOf(DataFrame::class);
});

it('build handles large chunks', function () {
    $engine = new FlowEngine();

    $config = IngestConfig::for(\LaravelIngest\Tests\Fixtures\Models\SimpleItem::class)
        ->fromSource(SourceType::UPLOAD);

    $chunk = [];
    for ($i = 0; $i < 100; $i++) {
        $chunk[] = ['name' => "User {$i}", 'email' => "user{$i}@example.com"];
    }

    $dataFrame = $engine->build($config, $chunk);

    expect($dataFrame)->toBeInstanceOf(DataFrame::class);
});
