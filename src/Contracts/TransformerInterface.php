<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

/**
 * Interface for data transformers that can be used with IngestConfig::mapAndTransform().
 *
 * Transformers implementing this interface provide a clean, testable way to transform
 * source data before it is persisted to the database. Unlike closures, transformers
 * can be unit tested in isolation and reused across multiple importers.
 *
 * @example
 * class DivideByHundredTransformer implements TransformerInterface
 * {
 *     public function transform(mixed $value, array $rowContext): mixed
 *     {
 *         return $value / 100;
 *     }
 * }
 *
 * // Usage in importer:
 * ->mapAndTransform('price_cents', 'price', DivideByHundredTransformer::class)
 */
interface TransformerInterface
{
    /**
     * Transform a value from the source data to the target format.
     *
     * @param  mixed  $value  The raw value from the source data
     * @param  array  $rowContext  The complete row data for context-aware transformations
     * @return mixed The transformed value to be assigned to the model attribute
     */
    public function transform(mixed $value, array $rowContext): mixed;
}
