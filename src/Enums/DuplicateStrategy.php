<?php

declare(strict_types=1);

namespace LaravelIngest\Enums;

enum DuplicateStrategy: string
{
    case UPDATE = 'update';
    case SKIP = 'skip';
    case FAIL = 'fail';
    case UPDATE_IF_NEWER = 'update_if_newer';
    case UPSERT = 'upsert';
}
