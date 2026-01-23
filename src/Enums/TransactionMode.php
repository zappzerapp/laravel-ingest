<?php

declare(strict_types=1);

namespace LaravelIngest\Enums;

enum TransactionMode: string
{
    case NONE = 'none';
    case CHUNK = 'chunk';
    case ROW = 'row';
}
