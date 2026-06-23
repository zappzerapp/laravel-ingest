<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

/**
 * Interface for field validators that can be used with IngestConfig::mapAndValidate().
 *
 * Validators implementing this interface provide a clean, testable way to validate
 * source data before transformation and persistence. Unlike array-based rules,
 * validators can be unit tested in isolation and reused across multiple importers.
 *
 * @example
 * class EmailValidator implements ValidatorInterface
 * {
 *     public function validate(mixed $value, array $rowContext): ValidationResult
 *     {
 *         if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
 *             return ValidationResult::fail('Invalid email format');
 *         }
 *         return ValidationResult::pass();
 *     }
 * }
 *
 * // Usage in importer:
 * ->mapAndValidate('email', 'email', EmailValidator::class)
 */
interface ValidatorInterface
{
    /**
     * @param  mixed  $value  The raw value from the source data
     * @param  array  $rowContext  The complete row data for context-aware validation
     * @return ValidationResult The result of validation (pass or fail with message)
     */
    public function validate(mixed $value, array $rowContext): ValidationResult;
}
