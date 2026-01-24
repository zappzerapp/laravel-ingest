<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

class RelationService
{
    public static function hasNestedKey(array $data, string $key): bool
    {
        if (!str_contains($key, '.')) {
            return array_key_exists($key, $data);
        }

        $segments = explode('.', $key);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    public static function createMissingRelation(array $relationConfig, mixed $relationValue, array &$relationCache, string $sourceField): mixed
    {
        $relatedModelClass = $relationConfig['model'];
        $lookupKey = $relationConfig['key'];

        $newRelatedModel = $relatedModelClass::create([
            $lookupKey => $relationValue,
        ]);

        if (!isset($relationCache[$sourceField])) {
            $relationCache[$sourceField] = [];
        }
        $relationCache[$sourceField][$relationValue] = $newRelatedModel->getKey();

        return $newRelatedModel->getKey();
    }
}
