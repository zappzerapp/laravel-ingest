<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Closure;

interface HasMappings
{
    public function map(string|array $sourceField, string $modelAttribute): static;

    public function mapAndTransform(
        string|array $sourceField,
        string $modelAttribute,
        Closure|TransformerInterface|string|array $transformer
    ): static;

    public function mapAndValidate(
        string|array $sourceField,
        string $modelAttribute,
        ValidatorInterface|string|array $validator
    ): static;

    public function keyedBy(string $sourceField): static;
}
