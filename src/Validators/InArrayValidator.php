<?php

declare(strict_types=1);

namespace LaravelIngest\Validators;

use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Contracts\ValidatorInterface;

readonly class InArrayValidator implements ValidatorInterface
{
    /**
     * @param  array<int, string>  $allowedValues
     */
    public function __construct(
        private array $allowedValues,
        private bool $strict = true
    ) {}

    public function validate(mixed $value, array $rowContext): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::pass();
        }

        if (!in_array($value, $this->allowedValues, $this->strict)) {
            $allowed = implode(', ', $this->allowedValues);

            return ValidationResult::fail("Value must be one of: {$allowed}");
        }

        return ValidationResult::pass();
    }
}
