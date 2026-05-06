<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Closure;

/**
 * Interface for conditional mappings that are only applied when a condition is met.
 *
 * Conditional mappings allow different field mappings based on row data context,
 * enabling dynamic mapping strategies without complex closure logic.
 *
 * @example
 * class OrderStatusMapping implements ConditionalMappingInterface
 * {
 *     public function shouldApply(array $rowContext): bool
 * {
 *         return $rowContext['type'] === 'order';
 *     }
 *
 *     public function getSourceField(): string
 * {
 *         return 'status';
 *     }
 *
 *     public function getModelAttribute(): string
 * {
 *         return 'order_status';
 * }
 * }
 *
 * // Usage:
 * ->mapWhen(new OrderStatusMapping())
 * ->mapWhen(new RefundStatusMapping())
 */
interface ConditionalMappingInterface
{
    /**
     * @param  array  $rowContext  The complete row data
     * @return bool True if this mapping should be applied
     */
    public function shouldApply(array $rowContext): bool;

    public function getSourceField(): string;

    public function getModelAttribute(): string;

    public function getTransformer(): TransformerInterface|Closure|string|null;

    public function getValidator(): ValidatorInterface|Closure|string|null;
}
