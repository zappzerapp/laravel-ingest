<?php

declare(strict_types=1);

namespace LaravelIngest\ValueObjects;

use DateTimeInterface;

readonly class Timestamp
{
    public function __construct(
        public DateTimeInterface|string|null $value
    ) {}

    public function toUnixTimestamp(): int|false
    {
        if ($this->value === null) {
            return false;
        }

        if ($this->value instanceof DateTimeInterface) {
            return $this->value->getTimestamp();
        }

        return strtotime((string) $this->value);
    }

    public function isNewerThan(self $other): bool
    {
        $thisTime = $this->toUnixTimestamp();
        $otherTime = $other->toUnixTimestamp();

        if ($thisTime === false || $otherTime === false) {
            return false;
        }

        return $thisTime > $otherTime;
    }

    public function isNull(): bool
    {
        return $this->value === null;
    }
}
