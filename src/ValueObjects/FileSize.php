<?php

declare(strict_types=1);

namespace LaravelIngest\ValueObjects;

use InvalidArgumentException;

readonly class FileSize
{
    public function __construct(
        public int $bytes
    ) {
        if ($bytes < 0) {
            throw new InvalidArgumentException('File size cannot be negative');
        }
    }

    public function inMegabytes(): float
    {
        return $this->bytes / (1024 * 1024);
    }

    public function exceeds(self $other): bool
    {
        return $this->bytes > $other->bytes;
    }

    public function toString(): string
    {
        return $this->inMegabytes() . ' MB';
    }
}
