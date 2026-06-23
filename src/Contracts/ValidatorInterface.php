<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

/**
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
 * ->mapAndValidate('email', 'email', EmailValidator::class)
 */
interface ValidatorInterface
{
    public function validate(mixed $value, array $rowContext): ValidationResult;
}
