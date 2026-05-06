<?php

declare(strict_types=1);

namespace LaravelIngest\Transformers;

use LaravelIngest\Contracts\TransformerInterface;

readonly class SlugTransformer implements TransformerInterface
{
    public function __construct(
        private string $separator = '-'
    ) {}

    public function transform(mixed $value, array $rowContext): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $slug = (string) $value;
        $slug = mb_strtolower($slug, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/u', $this->separator, $slug);
        $slug = trim($slug, $this->separator);

        return $slug;
    }
}
