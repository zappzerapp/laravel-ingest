<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use LaravelIngest\Contracts\TransformerInterface;

readonly class DefaultValueTransformer implements TransformerInterface
{
    public function __construct(
        private mixed $defaultValue,
        private array $emptyValues = [null, '']
    ) {}

    public function transform(mixed $value, array $rowContext): mixed
    {
        if (in_array($value, $this->emptyValues, true)) {
            return $this->defaultValue;
        }

        return $value;
    }
}
