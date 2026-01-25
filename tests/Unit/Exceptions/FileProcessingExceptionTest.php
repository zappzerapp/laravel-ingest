<?php

declare(strict_types=1);

use LaravelIngest\Exceptions\FileProcessingException;

it('can create a file too large exception', function () {
    $exception = FileProcessingException::fileTooLarge(10485760, 5242880);

    expect($exception)
        ->toBeInstanceOf(FileProcessingException::class)
        ->getMessage()->toBe('File size (10485760 bytes) exceeds maximum allowed size (5242880 bytes).');
});

it('can create an invalid mime type exception', function () {
    $allowed = ['text/csv', 'application/vnd.ms-excel'];
    $exception = FileProcessingException::invalidMimeType('application/pdf', $allowed);

    expect($exception)
        ->toBeInstanceOf(FileProcessingException::class)
        ->getMessage()->toBe('File type \'application/pdf\' is not allowed. Allowed types: text/csv, application/vnd.ms-excel');
});

it('can create a malicious content exception', function () {
    $exception = FileProcessingException::maliciousContent();

    expect($exception)
        ->toBeInstanceOf(FileProcessingException::class)
        ->getMessage()->toBe('File contains potentially malicious content.');
});

it('can create an unreadable file exception', function () {
    $exception = FileProcessingException::unreadableFile('/path/to/file.csv');

    expect($exception)
        ->toBeInstanceOf(FileProcessingException::class)
        ->getMessage()->toContain('Unable to read file')
        ->and($exception->getCode())->toBe(0);

});

it('can create an unreadable file exception with previous', function () {
    $previous = new RuntimeException('File not found');
    $exception = FileProcessingException::unreadableFile('/path/to/file.csv', $previous);

    expect($exception)
        ->toBeInstanceOf(FileProcessingException::class)
        ->and($exception->getPrevious())->toBe($previous);

});

it('can create a corrupted file exception', function () {
    $exception = FileProcessingException::corruptedFile('/path/to/file.csv');

    expect($exception)
        ->toBeInstanceOf(FileProcessingException::class)
        ->getMessage()->toBe('The file appears to be corrupted or invalid.');
});

it('can create a corrupted file exception with previous', function () {
    $previous = new RuntimeException('Invalid data');
    $exception = FileProcessingException::corruptedFile('/path/to/file.csv', $previous);

    expect($exception)
        ->toBeInstanceOf(FileProcessingException::class)
        ->and($exception->getPrevious())->toBe($previous);

});
