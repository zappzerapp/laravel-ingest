<?php

declare(strict_types=1);

namespace LaravelIngest\Enums;

enum IngestStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case FAILED = 'failed';
}