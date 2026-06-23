<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use LaravelIngest\Contracts\TransformerInterface;

readonly class TrimTransformer implements TransformerInterface
{
    public function __construct(
        private ?string $characterMask = null
    ) {}

    public function transform(mixed $value, array $rowContext): mixed
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        if ($this->characterMask !== null) {
            return trim($string, $this->characterMask);
        }

        return trim($string);
    }
}
