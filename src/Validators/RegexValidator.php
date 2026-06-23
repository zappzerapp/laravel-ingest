<?php

declare(strict_types=1);

namespace LaravelIngest\Validators;

use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Contracts\ValidatorInterface;

readonly class RegexValidator implements ValidatorInterface
{
    public function __construct(
        private string $pattern,
        private ?string $message = null
    ) {}

    public function validate(mixed $value, array $rowContext): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::pass();
        }

        if (!preg_match($this->pattern, (string) $value)) {
            $message = $this->message ?? 'Value does not match required pattern';

            return ValidationResult::fail($message);
        }

        return ValidationResult::pass();
    }
}
