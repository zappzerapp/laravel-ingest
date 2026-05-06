<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use LaravelIngest\Contracts\TransformerInterface;

readonly class MapTransformer implements TransformerInterface
{
    /**
     * @param  array<string, mixed>  $mapping
     */
    public function __construct(
        private array $mapping,
        private mixed $default = null
    ) {}

    public function transform(mixed $value, array $rowContext): mixed
    {
        if ($value === null || $value === '') {
            return $this->default;
        }

        $stringValue = (string) $value;

        return $this->mapping[$stringValue] ?? $this->default;
    }
}
