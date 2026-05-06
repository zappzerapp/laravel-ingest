<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

/**
 * Immutable value object representing the result of a validation.
 *
 * @example
 * // Success
 * return ValidationResult::pass();
 *
 * // Failure with message
 * return ValidationResult::fail('Price must be greater than 0');
 *
 * // Failure with multiple messages
 * return ValidationResult::fail(['Price too low', 'Invalid currency']);
 */
final readonly class ValidationResult
{
    private function __construct(
        private bool $passed,
        private array $errors = []
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function fail(string|array $errors): self
    {
        $errors = is_array($errors) ? $errors : [$errors];

        return new self(false, $errors);
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function failed(): bool
    {
        return !$this->passed;
    }

    /**
     * @return array<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
