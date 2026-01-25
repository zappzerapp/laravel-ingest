<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use JsonException;

class ErrorMessageService
{
    private static bool $isProduction = false;

    public static function setEnvironment(bool $isProduction): void
    {
        self::$isProduction = $isProduction;
    }

    public static function sanitize(string $message, array $context = []): string
    {
        if (!self::$isProduction) {
            return $message;
        }

        $sanitized = $message;

        $sanitized = preg_replace('/(mysql|pgsql|sqlite):\/\/[^\s@]+@[^\s\/]+/', '[REDACTED_DB]', $sanitized);

        $sanitized = preg_replace(
            '/(^|[\s\'"(\[])(\/(?:Users|home|var|opt|srv|etc|app|storage|tmp|data|imports|ingest-[^\s\/\'"]+)\/[\w.\-\/]+)(?=$|[\s\'")\]])/',
            '$1[REDACTED_PATH]',
            $sanitized
        );

        $sanitized = preg_replace('/[A-Za-z0-9]{40,}/', '[REDACTED_TOKEN]', $sanitized);

        $sanitized = preg_replace('/#\d+\s+.*$/m', '', $sanitized);

        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 197) . '...';
        }

        return $sanitized ?: 'An error occurred during processing.';
    }

    public static function createUserMessage(string $type): string
    {
        $messages = [
            'file_not_found' => 'The requested file could not be found or has been removed.',
            'permission_denied' => 'You do not have permission to access this resource.',
            'validation_failed' => 'The provided data is invalid or incomplete.',
            'processing_error' => 'An error occurred while processing your request.',
            'timeout' => 'The request took too long to process. Please try again.',
            'rate_limit' => 'Too many requests. Please try again later.',
            'server_error' => 'An internal error occurred. Please try again later.',
            'invalid_format' => 'The file format is not supported.',
            'file_too_large' => 'The file is too large. Please upload a smaller file.',
            'corrupted_file' => 'The file appears to be corrupted or invalid.',
        ];

        return $messages[$type] ?? 'An unexpected error occurred.';
    }

    /**
     * @throws JsonException
     */
    public static function createLogMessage(string $message, array $context = []): string
    {
        $logContext = [];

        foreach ($context as $key => $value) {
            if (is_string($value) && strlen($value) > 100) {
                $logContext[$key] = substr($value, 0, 100) . '...';
            } else {
                $logContext[$key] = $value;
            }
        }

        if (!empty($logContext)) {
            $message .= ' Context: ' . json_encode($logContext, JSON_THROW_ON_ERROR);
        }

        return $message;
    }
}
