<?php

declare(strict_types=1);

namespace LaravelIngest\Validators;

use DateTimeImmutable;
use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Contracts\ValidatorInterface;

readonly class DateValidator implements ValidatorInterface
{
    public function __construct(
        private string $format = 'Y-m-d',
        private ?string $message = null
    ) {}

    public function validate(mixed $value, array $rowContext): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::pass();
        }

        $date = DateTimeImmutable::createFromFormat($this->format, (string) $value);

        if ($date === false || $date->format($this->format) !== (string) $value) {
            $message = $this->message ?? "Invalid date format. Expected: {$this->format}";

            return ValidationResult::fail($message);
        }

        return ValidationResult::pass();
    }
}
