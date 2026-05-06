<?php

declare(strict_types=1);

use Generator;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\Product;

it('enables tracing with withTracing', function () {
    $config = IngestConfig::for(Product::class)
        ->withTracing();

    expect($config->tracingEnabled)->toBeTrue()
        ->and($config->traceTransformations)->toBeTrue()
        ->and($config->traceMappings)->toBeTrue();
});

it('enables only transformation tracing', function () {
    $config = IngestConfig::for(Product::class)
        ->traceTransformations();

    expect($config->tracingEnabled)->toBeTrue()
        ->and($config->traceTransformations)->toBeTrue()
        ->and($config->traceMappings)->toBeFalse();
});

it('enables only mapping tracing', function () {
    $config = IngestConfig::for(Product::class)
        ->traceMappings();

    expect($config->tracingEnabled)->toBeTrue()
        ->and($config->traceTransformations)->toBeFalse()
        ->and($config->traceMappings)->toBeTrue();
});

it('sets expected schema', function () {
    $schema = [
        'id' => ['type' => 'integer', 'required' => true],
        'name' => ['type' => 'string', 'required' => true],
    ];

    $config = IngestConfig::for(Product::class)
        ->expectSchema($schema);

    expect($config->expectedSchema)->toBe($schema);
});

it('adds conditional mapping with closure condition', function () {
    $config = IngestConfig::for(Product::class)
        ->mapWhen('status', 'product_status', fn($row) => $row['type'] === 'product');

    expect($config->conditionalMappings)->toHaveCount(1)
        ->and($config->conditionalMappings[0]['sourceField'])->toBe('status')
        ->and($config->conditionalMappings[0]['attribute'])->toBe('product_status');
});

it('adds conditional mapping with transformer', function () {
    $config = IngestConfig::for(Product::class)
        ->mapWhen(
            'price',
            'price_cents',
            fn($row) => $row['currency'] === 'USD',
            fn($v) => $v * 100
        );

    expect($config->conditionalMappings)->toHaveCount(1)
        ->and($config->conditionalMappings[0]['transformer'])->not->toBeNull();
});

it('adds conditional mapping with validator', function () {
    $config = IngestConfig::for(Product::class)
        ->mapWhen(
            'email',
            'contact_email',
            fn($row) => $row['has_email'] === true,
            null,
            LaravelIngest\Validators\EmailValidator::class
        );

    expect($config->conditionalMappings)->toHaveCount(1)
        ->and($config->conditionalMappings[0]['validator'])->not->toBeNull();
});

it('supports aliases in conditional mapping', function () {
    $config = IngestConfig::for(Product::class)
        ->mapWhen(['status', 'order_status'], 'product_status', fn($row) => true);

    expect($config->conditionalMappings[0]['sourceField'])->toBe('status')
        ->and($config->conditionalMappings[0]['aliases'])->toBe(['order_status']);
});

it('returns true when shouldApplyConditional matches closure', function () {
    $config = IngestConfig::for(Product::class);

    $conditional = [
        'sourceField' => 'status',
        'attribute' => 'product_status',
        'condition' => fn($row) => $row['type'] === 'product',
        'transformer' => null,
        'validator' => null,
        'aliases' => [],
    ];

    expect($config->shouldApplyConditional($conditional, ['type' => 'product']))->toBeTrue()
        ->and($config->shouldApplyConditional($conditional, ['type' => 'order']))->toBeFalse();
});

it('returns true for non-closure non-interface condition', function () {
    $config = IngestConfig::for(Product::class);

    $conditional = [
        'sourceField' => 'status',
        'attribute' => 'product_status',
        'condition' => true, // Not a Closure or ConditionalMappingInterface
        'transformer' => null,
        'validator' => null,
        'aliases' => [],
    ];

    expect($config->shouldApplyConditional($conditional, ['type' => 'product']))->toBeTrue();
});

it('returns result from ConditionalMappingInterface', function () {
    $config = IngestConfig::for(Product::class);

    $condition = new class() implements LaravelIngest\Contracts\ConditionalMappingInterface
    {
        public function shouldApply(array $rowContext): bool
        {
            return $rowContext['type'] === 'product';
        }

        public function getSourceField(): string
        {
            return 'status';
        }

        public function getModelAttribute(): string
        {
            return 'product_status';
        }

        public function getTransformer(): ?LaravelIngest\Contracts\TransformerInterface
        {
            return null;
        }

        public function getValidator(): ?LaravelIngest\Contracts\ValidatorInterface
        {
            return null;
        }
    };

    $conditional = [
        'sourceField' => 'status',
        'attribute' => 'product_status',
        'condition' => $condition,
        'transformer' => null,
        'validator' => null,
        'aliases' => [],
    ];

    expect($config->shouldApplyConditional($conditional, ['type' => 'product']))->toBeTrue()
        ->and($config->shouldApplyConditional($conditional, ['type' => 'order']))->toBeFalse();
});

it('registers event handler', function () {
    $handler = new class() implements LaravelIngest\Contracts\ImportEventHandlerInterface
    {
        public function beforeImport(LaravelIngest\Models\IngestRun $run): void {}

        public function onRowProcessed(LaravelIngest\Models\IngestRun $run, LaravelIngest\DTOs\RowData $row, object $model): void {}

        public function onError(LaravelIngest\Models\IngestRun $run, LaravelIngest\DTOs\RowData $row, Throwable $error): void {}

        public function afterImport(LaravelIngest\Models\IngestRun $run, LaravelIngest\ValueObjects\ImportStats $stats): void {}
    };

    $config = IngestConfig::for(Product::class)
        ->withEventHandler($handler);

    expect($config->eventHandler)->toBe($handler);
});

it('accepts custom SourceInterface', function () {
    $source = new class() implements LaravelIngest\Contracts\SourceInterface
    {
        public function read(): Generator
        {
            yield [];
        }

        public function getSchema(): array
        {
            return [];
        }

        public function getSourceMetadata(): array
        {
            return [];
        }
    };

    $config = IngestConfig::for(Product::class)
        ->fromSource($source);

    expect($config->sourceType)->toBe($source);
});
