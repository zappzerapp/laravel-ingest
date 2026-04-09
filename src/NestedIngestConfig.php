<?php

declare(strict_types=1);

namespace LaravelIngest;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Contracts\TransformerInterface;
use LaravelIngest\Contracts\ValidatorInterface;

class NestedIngestConfig
{
    public array $mappings = [];
    public array $transformers = [];
    public array $validators = [];

    public function map(string|array $sourceField, string $modelAttribute): self
    {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'aliases' => $aliases,
        ];

        return $this;
    }

    public function mapAndTransform(
        string|array $sourceField,
        string $modelAttribute,
        Closure|TransformerInterface|string $transformer
    ): self {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'aliases' => $aliases,
        ];

        $this->transformers[$primaryField] = $transformer instanceof Closure
            ? new SerializableClosure($transformer)
            : $transformer;

        return $this;
    }

    public function mapAndValidate(
        string|array $sourceField,
        string $modelAttribute,
        ValidatorInterface|string $validator
    ): self {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'aliases' => $aliases,
        ];

        $this->validators[$primaryField] = $validator;

        return $this;
    }

    public function keyedBy(string $sourceField): self
    {
        $this->mappings['_keyedBy'] = $sourceField;

        return $this;
    }

    public function getMappings(): array
    {
        return $this->mappings;
    }

    public function getTransformers(): array
    {
        return $this->transformers;
    }

    public function getValidators(): array
    {
        return $this->validators;
    }
}
