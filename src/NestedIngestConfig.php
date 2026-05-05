<?php

declare(strict_types=1);

namespace LaravelIngest;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Contracts\HasMappings;
use LaravelIngest\Contracts\MappingInterface;
use LaravelIngest\Contracts\NestedMappingInterface;
use LaravelIngest\Contracts\TransformerInterface;
use LaravelIngest\Contracts\ValidatorInterface;

class NestedIngestConfig implements HasMappings
{
    public array $mappings = [];
    public array $transformers = [];
    public array $validators = [];

    public function map(string|array $sourceField, string $modelAttribute): static
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
        Closure|TransformerInterface|string|array $transformer
    ): static {
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
        ValidatorInterface|string|array $validator
    ): static {
        $primaryField = is_array($sourceField) ? $sourceField[0] : $sourceField;
        $aliases = is_array($sourceField) ? array_slice($sourceField, 1) : [];

        $this->mappings[$primaryField] = [
            'attribute' => $modelAttribute,
            'aliases' => $aliases,
        ];

        $this->validators[$primaryField] = $validator;

        return $this;
    }

    public function keyedBy(string $sourceField): static
    {
        $this->mappings['_keyedBy'] = $sourceField;

        return $this;
    }

    public function applyMapping(MappingInterface $mapping, string $prefix = ''): static
    {
        if ($mapping instanceof NestedMappingInterface) {
            return $mapping->applyNested($this, $prefix);
        }

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
