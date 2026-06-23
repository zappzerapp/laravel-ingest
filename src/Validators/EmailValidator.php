<?php

declare(strict_types=1);

namespace LaravelIngest\Validators;

use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Contracts\ValidatorInterface;

readonly class EmailValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $rowContext): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::pass();
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ValidationResult::fail('Invalid email format');
        }

        return ValidationResult::pass();
    }
}
