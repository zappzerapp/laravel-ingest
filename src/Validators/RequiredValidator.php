<?php

declare(strict_types=1);

namespace LaravelIngest\Validators;

use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Contracts\ValidatorInterface;

readonly class RequiredValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $rowContext): ValidationResult
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return ValidationResult::fail('Field is required');
        }

        return ValidationResult::pass();
    }
}
