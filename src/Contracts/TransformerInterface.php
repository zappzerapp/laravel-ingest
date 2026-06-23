<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

/**
 * @example
 * class DivideByHundredTransformer implements TransformerInterface
 * {
 *     public function transform(mixed $value, array $rowContext): mixed
 *     {
 *         return $value / 100;
 *     }
 * }
 *
 * ->mapAndTransform('price_cents', 'price', DivideByHundredTransformer::class)
 */
interface TransformerInterface
{
    public function transform(mixed $value, array $rowContext): mixed;
}
