<?php

declare(strict_types=1);

namespace LaravelIngest\Exceptions;

use Exception;
use LaravelIngest\Services\ErrorMessageService;
use Throwable;

class FileProcessingException extends Exception
{
    public static function fileTooLarge(int $size, int $maxSize): self
    {
        return new self("File size ({$size} bytes) exceeds maximum allowed size ({$maxSize} bytes).");
    }

    public static function invalidMimeType(string $mimeType, array $allowed): self
    {
        return new self("File type '{$mimeType}' is not allowed. Allowed types: " . implode(', ', $allowed));
    }

    public static function maliciousContent(): self
    {
        return new self('File contains potentially malicious content.');
    }

    public static function unreadableFile(string $path, ?Throwable $previous = null): self
    {
        $message = ErrorMessageService::sanitize(
            "Unable to read file at path: {$path}",
            ['path' => $path]
        );

        return new self($message, 0, $previous);
    }

    public static function corruptedFile(string $path, ?Throwable $previous = null): self
    {
        $message = ErrorMessageService::createUserMessage('corrupted_file');

        return new self($message, 0, $previous);
    }
}
