<?php

declare(strict_types=1);

namespace LaravelIngest\ValueObjects;

final readonly class ImportCounts
{
    public function __construct(
        public int $success,
        public int $failure,
        public int $created,
        public int $updated,
    ) {}
}
