<?php

declare(strict_types=1);

namespace LaravelIngest\Validators;

use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Contracts\ValidatorInterface;

readonly class RangeValidator implements ValidatorInterface
{
    public function __construct(
        private ?float $min = null,
        private ?float $max = null,
        private ?string $message = null
    ) {}

    public function validate(mixed $value, array $rowContext): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::pass();
        }

        $numeric = is_numeric($value) ? (float) $value : null;

        if ($numeric === null) {
            return ValidationResult::fail('Value must be numeric');
        }

        if ($this->min !== null && $numeric < $this->min) {
            $message = $this->message ?? "Value must be at least {$this->min}";

            return ValidationResult::fail($message);
        }

        if ($this->max !== null && $numeric > $this->max) {
            $message = $this->message ?? "Value must be at most {$this->max}";

            return ValidationResult::fail($message);
        }

        return ValidationResult::pass();
    }
}
