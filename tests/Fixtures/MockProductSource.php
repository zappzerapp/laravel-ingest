<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures;

use Generator;
use LaravelIngest\Contracts\SourceInterface;

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
