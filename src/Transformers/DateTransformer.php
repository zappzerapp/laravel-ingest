<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use DateTimeImmutable;
use DateTimeInterface;
use LaravelIngest\Contracts\TransformerInterface;

class DateTransformer implements TransformerInterface
{
    /**
     * @param string $inputFormat Expected input format for DateTime::createFromFormat
     * @param string $outputFormat Output format for database storage
     * @param mixed $default Default value when parsing fails
     */
    public function __construct(
        private string $inputFormat = 'Y-m-d',
        private string $outputFormat = 'Y-m-d',
        private mixed $default = null
    ) {
    }

    public function transform(mixed $value, array $rowContext): mixed
    {
        if ($value === null || $value === '') {
            return $this->default;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($this->outputFormat);
        }

        $dateTime = DateTimeImmutable::createFromFormat($this->inputFormat, (string) $value);

        if ($dateTime === false) {
            return $this->default;
        }

        return $dateTime->format($this->outputFormat);
    }
}
