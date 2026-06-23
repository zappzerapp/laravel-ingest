<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Closure;

/**
 * @example
 * class OrderStatusMapping implements ConditionalMappingInterface
 * {
 *     public function shouldApply(array $rowContext): bool
 *     {
 *         return $rowContext['type'] === 'order';
 *     }
 *
 *     public function getSourceField(): string
 *     {
 *         return 'status';
 *     }
 *
 *     public function getModelAttribute(): string
 *     {
 *         return 'order_status';
 *     }
 * }
 *
 * ->mapWhen(new OrderStatusMapping())
 * ->mapWhen(new RefundStatusMapping())
 */
interface ConditionalMappingInterface
{
    public function shouldApply(array $rowContext): bool;

    public function getSourceField(): string;

    public function getModelAttribute(): string;

    public function getTransformer(): TransformerInterface|Closure|string|null;

    public function getValidator(): ValidatorInterface|Closure|string|null;
}
