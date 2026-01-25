<?php

declare(strict_types=1);

use LaravelIngest\Services\ErrorMessageService;

afterEach(function () {
    ErrorMessageService::setEnvironment(false);
});

it('returns messages unchanged by default', function () {
    $message = ErrorMessageService::sanitize('Simple error message');

    expect($message)->toBe('Simple error message');
});

it('sanitizes messages in production environment', function () {
    ErrorMessageService::setEnvironment(true);

    $original = 'Error at /var/www/html/app/User.php';
    $sanitized = ErrorMessageService::sanitize($original);

    expect($sanitized)->toContain('[REDACTED_PATH]')
        ->and($sanitized)->not->toContain('/var/www/html/app/User.php');
});

it('sanitizes db connection strings in production', function () {
    ErrorMessageService::setEnvironment(true);

    $original = 'Connection failed: mysql://user:password@localhost/db';
    $sanitized = ErrorMessageService::sanitize($original);

    expect($sanitized)->toContain('[REDACTED_DB]')
        ->and($sanitized)->not->toContain('mysql://');
});

it('sanitizes tokens in production', function () {
    ErrorMessageService::setEnvironment(true);

    $original = 'Invalid token: abcdefghijklmnopqrstuvwxyz1234567890123456';
    $sanitized = ErrorMessageService::sanitize($original);

    expect($sanitized)->toContain('[REDACTED_TOKEN]')
        ->and($sanitized)->not->toContain('abcdefghijklmnopqrstuvwxyz1234567890123456');
});

it('sanitizes stack traces in production', function () {
    ErrorMessageService::setEnvironment(true);

    $original = "Error occurred\n#0 /path/to/file.php(10): SomeClass->method()";
    $sanitized = ErrorMessageService::sanitize($original);

    expect($sanitized)->not->toContain('#0 /path/to/file.php');
});

it('truncates long messages in production', function () {
    ErrorMessageService::setEnvironment(true);

    $longMessage = str_repeat('word ', 50);
    $sanitized = ErrorMessageService::sanitize($longMessage);

    expect(strlen($sanitized))->toBeLessThan(205)
        ->and($sanitized)->toEndWith('...');
});

it('can create user-friendly error messages', function () {
    expect(ErrorMessageService::createUserMessage('file_not_found'))
        ->toBe('The requested file could not be found or has been removed.');
});

it('returns default message for unknown error type', function () {
    $message = ErrorMessageService::createUserMessage('unknown_type');

    expect($message)->toBe('An unexpected error occurred.');
});

it('can create log messages with context', function () {
    $message = ErrorMessageService::createLogMessage('Error occurred', ['user_id' => 1]);

    expect($message)->toContain('Error occurred')
        ->and($message)->toContain('"user_id":1');
});

it('truncates long context values in log messages', function () {
    $longValue = str_repeat('b', 150);
    $message = ErrorMessageService::createLogMessage('Error', ['data' => $longValue]);

    expect($message)->toContain(str_repeat('b', 100))
        ->and($message)->toContain('...');

    $truncated = substr($longValue, 0, 100) . '...';
    expect($message)->toContain(json_encode(['data' => $truncated]));
});
