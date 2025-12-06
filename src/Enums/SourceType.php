<?php

declare(strict_types=1);

namespace LaravelIngest\Enums;

enum SourceType: string
{
    case UPLOAD = 'upload';
    case FTP = 'ftp';
    case SFTP = 'sftp';
    case URL = 'url';
    case FILESYSTEM = 'filesystem';
}
