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
     * @param  string  $decimalSeparator  Character used as decimal separator
     * @param  string  $thousandsSeparator  Character used as thousands separator to strip
     */
    public function __construct(
        private ?int $decimals = null,
        private ?float $min = null,
        private ?float $max = null,
        private mixed $default = null,
        private string $decimalSeparator = '.',
        private string $thousandsSeparator = ','
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

        $number = (float) $numericString;

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
        $value = str_replace($this->thousandsSeparator, '', $value);

        if ($this->decimalSeparator !== '.') {
            $value = str_replace($this->decimalSeparator, '.', $value);
        }

        return trim($value);
    }
}
