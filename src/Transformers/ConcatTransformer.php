<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use LaravelIngest\Contracts\TransformerInterface;

readonly class ConcatTransformer implements TransformerInterface
{
    /**
     * @param  array<string>  $fields
     */
    public function __construct(
        private array $fields,
        private string $separator = ' '
    ) {}

    public function transform(mixed $value, array $rowContext): mixed
    {
        $parts = [];

        foreach ($this->fields as $field) {
            $fieldValue = $rowContext[$field] ?? null;
            if ($fieldValue !== null && $fieldValue !== '') {
                $parts[] = $fieldValue;
            }
        }

        if (empty($parts)) {
            return null;
        }

        return implode($this->separator, $parts);
    }
}
