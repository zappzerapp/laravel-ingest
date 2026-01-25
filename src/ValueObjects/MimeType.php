<?php

declare(strict_types=1);

namespace LaravelIngest\ValueObjects;

use InvalidArgumentException;

readonly class MimeType
{
    public function __construct(
        public string $type
    ) {
        if (empty(trim($type))) {
            throw new InvalidArgumentException('MIME type cannot be empty');
        }
    }

    public function isIn(array $allowedTypes): bool
    {
        return in_array($this->type, $allowedTypes, true);
    }

    public function isTextType(): bool
    {
        return in_array($this->type, ['text/plain', 'text/csv'], true);
    }

    public function toString(): string
    {
        return $this->type;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type;
    }
}
