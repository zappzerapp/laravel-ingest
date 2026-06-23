<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use LaravelIngest\Contracts\TransformerInterface;

class NumericTransformer implements TransformerInterface
{
    /**
     * @param  int|null  $decimals  Number of decimal places, null for no rounding
     * @param  float|null  $min  Minimum allowed value, null for no minimum
     * @param  float|null  $max  Maximum allowed value, null for no maximum
     * @param  mixed  $default  Default value when conversion fails
     * @param  array{decimal?: string, thousands?: string}  $separators
     */
    public function __construct(
        private ?int $decimals = null,
        private ?float $min = null,
        private ?float $max = null,
        private mixed $default = null,
        private array $separators = ['decimal' => '.', 'thousands' => ',']
    ) {}

    public function transform(mixed $value, array $rowContext): mixed
    {
        if ($value === null || $value === '') {
            return $this->default;
        }

        $numericString = $this->normalizeNumber((string) $value);

        if (!is_numeric($numericString)) {
            return $this->default;
        }

        return $this->applyNumericConstraints((float) $numericString);
    }

    private function applyNumericConstraints(float $number): float
    {
        if ($this->min !== null && $number < $this->min) {
            return $this->min;
        }

        if ($this->max !== null && $number > $this->max) {
            return $this->max;
        }

        if ($this->decimals !== null) {
            return round($number, $this->decimals);
        }

        return $number;
    }

    private function normalizeNumber(string $value): string
    {
        $thousandsSeparator = $this->separators['thousands'] ?? ',';
        $decimalSeparator = $this->separators['decimal'] ?? '.';

        $value = str_replace($thousandsSeparator, '', $value);

        if ($decimalSeparator !== '.') {
            $value = str_replace($decimalSeparator, '.', $value);
        }

        return trim($value);
    }
}
