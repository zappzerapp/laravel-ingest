<?php

declare(strict_types=1);

namespace LaravelIngest\Enums;

enum IngestStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
