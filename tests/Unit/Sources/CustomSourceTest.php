<?php

declare(strict_types=1);

use LaravelIngest\Contracts\SourceInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\Product;

class MockProductSource implements SourceInterface
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function read(): Generator
    {
        foreach ($this->data as $item) {
            yield $item;
        }
    }

    public function getSchema(): array
    {
        return [
            'id' => ['type' => 'integer', 'required' => true],
            'name' => ['type' => 'string', 'required' => true],
            'price' => ['type' => 'numeric', 'required' => false],
        ];
    }

    public function getSourceMetadata(): array
    {
        return [
            'source_type' => 'mock',
            'total_count' => count($this->data),
        ];
    }
}

it('accepts custom source interface', function () {
    $source = new MockProductSource([
        ['id' => 1, 'name' => 'Product 1', 'price' => 100],
    ]);

    $config = IngestConfig::for(Product::class)
        ->fromSource($source);

    expect($config->sourceType)->toBe($source)
        ->and($config->sourceType)->toBeInstanceOf(SourceInterface::class);
});

it('preserves source options when using custom source', function () {
    $source = new MockProductSource([]);

    $config = IngestConfig::for(Product::class)
        ->fromSource($source, ['filter' => 'active']);

    expect($config->sourceOptions)->toBe(['filter' => 'active']);
});

it('can iterate over source data', function () {
    $data = [
        ['id' => 1, 'name' => 'Product 1'],
        ['id' => 2, 'name' => 'Product 2'],
        ['id' => 3, 'name' => 'Product 3'],
    ];

    $source = new MockProductSource($data);
    $items = iterator_to_array($source->read());

    expect($items)->toHaveCount(3)
        ->and($items[0]['name'])->toBe('Product 1');
});

it('provides schema from source', function () {
    $source = new MockProductSource([]);
    $schema = $source->getSchema();

    expect($schema)->toHaveKey('id')
        ->and($schema['id'])->toBe(['type' => 'integer', 'required' => true])
        ->and($schema)->toHaveKey('price');
});

it('provides metadata from source', function () {
    $source = new MockProductSource([
        ['id' => 1],
        ['id' => 2],
    ]);

    $metadata = $source->getSourceMetadata();

    expect($metadata)->toHaveKey('total_count')
        ->and($metadata['total_count'])->toBe(2);
});
