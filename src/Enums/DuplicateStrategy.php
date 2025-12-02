<?php

namespace LaravelIngest\Enums;

enum DuplicateStrategy: string
{
    case UPDATE = 'update';
    case SKIP = 'skip';
    case FAIL = 'fail';
}