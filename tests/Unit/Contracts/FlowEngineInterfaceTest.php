<?php

declare(strict_types=1);

use Flow\ETL\DataFrame;
use LaravelIngest\Contracts\FlowEngineInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\User;

it('has the correct interface methods', function () {
    $reflection = new ReflectionClass(FlowEngineInterface::class);

    expect($reflection->hasMethod('build'))->toBeTrue()
        ->and($reflection->hasMethod('execute'))->toBeTrue();
});

it('build method has correct signature', function () {
    $reflection = new ReflectionClass(FlowEngineInterface::class);
    $method = $reflection->getMethod('build');

    expect($method->getReturnType()?->getName())->toBe(DataFrame::class)
        ->and($method->getNumberOfParameters())->toBe(4);

    $params = $method->getParameters();
    expect($params[0]->getName())->toBe('config')
        ->and($params[0]->getType()?->getName())->toBe(IngestConfig::class)
        ->and($params[1]->getName())->toBe('chunk')
        ->and($params[1]->getType()?->getName())->toBe('array')
        ->and($params[2]->getName())->toBe('ingestRun')
        ->and($params[3]->getName())->toBe('isDryRun')
        ->and($params[3]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[3]->getDefaultValue())->toBeFalse();
});

it('execute method has correct signature', function () {
    $reflection = new ReflectionClass(FlowEngineInterface::class);
    $method = $reflection->getMethod('execute');

    expect($method->getReturnType()?->getName())->toBe('void')
        ->and($method->getNumberOfParameters())->toBe(1);

    $params = $method->getParameters();
    expect($params[0]->getName())->toBe('pipeline')
        ->and($params[0]->getType()?->getName())->toBe(DataFrame::class);
});

it('can be implemented by a concrete class', function () {
    $mockEngine = new class() implements FlowEngineInterface
    {
        public function build(IngestConfig $config, array $chunk, ?LaravelIngest\Models\IngestRun $ingestRun = null, bool $isDryRun = false): DataFrame
        {
            throw new RuntimeException('Not implemented');
        }

        public function execute(DataFrame $pipeline): void
        {
            throw new RuntimeException('Not implemented');
        }
    };

    expect($mockEngine)->toBeInstanceOf(FlowEngineInterface::class);
});

it('throws runtime exception from mock build implementation', function () {
    $mockEngine = new class() implements FlowEngineInterface
    {
        public function build(IngestConfig $config, array $chunk, ?LaravelIngest\Models\IngestRun $ingestRun = null, bool $isDryRun = false): DataFrame
        {
            throw new RuntimeException('Build not implemented');
        }

        public function execute(DataFrame $pipeline): void
        {
            // do nothing
        }
    };

    $config = IngestConfig::for(User::class);

    expect(fn() => $mockEngine->build($config, []))
        ->toThrow(RuntimeException::class, 'Build not implemented');
});

it('execute method accepts DataFrame parameter', function () {
    $capturedPipeline = null;

    $mockEngine = new class($capturedPipeline) implements FlowEngineInterface
    {
        public ?DataFrame $capturedPipeline = null;

        public function __construct(?DataFrame &$capturedPipeline)
        {
            $this->capturedPipeline = &$capturedPipeline;
        }

        public function build(IngestConfig $config, array $chunk, ?LaravelIngest\Models\IngestRun $ingestRun = null, bool $isDryRun = false): DataFrame
        {
            throw new RuntimeException('Not implemented');
        }

        public function execute(DataFrame $pipeline): void
        {
            $this->capturedPipeline = $pipeline;
        }
    };

    expect($capturedPipeline)->toBeNull();
});
