<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use LaravelIngest\Contracts\TransformerInterface;

class BooleanTransformer implements TransformerInterface
{
    /**
     * @var array<string>
     */
    private array $truthyValues;

    /**
     * @var array<string>
     */
    private array $falsyValues;

    private bool $caseSensitive;
    private mixed $default;

    /**
     * @param  array<string>  $truthyValues
     * @param  array<string>  $falsyValues
     */
    public function __construct(
        array $truthyValues = ['yes', 'true', '1', 'on', 'y'],
        array $falsyValues = ['no', 'false', '0', 'off', 'n'],
        bool $caseSensitive = false,
        mixed $default = null
    ) {
        $this->truthyValues = $truthyValues;
        $this->falsyValues = $falsyValues;
        $this->caseSensitive = $caseSensitive;
        $this->default = $default;
    }

    public function transform(mixed $value, array $rowContext): mixed
    {
        if ($value === null || $value === '') {
            return $this->default;
        }

        $stringValue = (string) $value;
        $compareValue = $this->caseSensitive ? $stringValue : strtolower($stringValue);

        if (in_array($compareValue, $this->truthyValues, true)) {
            return 1;
        }

        if (in_array($compareValue, $this->falsyValues, true)) {
            return 0;
        }

        return $this->default;
    }
}
