<?php

declare(strict_types=1);

use LaravelIngest\ValueObjects\MimeType;

it('creates mime type with valid type', function () {
    $mimeType = new MimeType('text/plain');

    expect($mimeType->type)->toBe('text/plain');
});

it('throws exception for empty mime type', function () {
    new MimeType('');
})->throws(InvalidArgumentException::class, 'MIME type cannot be empty');

it('throws exception for whitespace-only mime type', function () {
    new MimeType('   ');
})->throws(InvalidArgumentException::class, 'MIME type cannot be empty');

it('checks if mime type is in allowed types', function () {
    $mimeType = new MimeType('text/plain');

    expect($mimeType->isIn(['text/plain', 'text/csv']))->toBeTrue();
    expect($mimeType->isIn(['application/json']))->toBeFalse();
});

it('checks if mime type is text type', function () {
    $plainText = new MimeType('text/plain');
    $csv = new MimeType('text/csv');
    $json = new MimeType('application/json');

    expect($plainText->isTextType())->toBeTrue();
    expect($csv->isTextType())->toBeTrue();
    expect($json->isTextType())->toBeFalse();
});

it('converts to string representation', function () {
    $mimeType = new MimeType('application/json');

    expect($mimeType->toString())->toBe('application/json');
});

it('checks equality with another mime type', function () {
    $first = new MimeType('text/plain');
    $second = new MimeType('text/plain');
    $third = new MimeType('text/csv');

    expect($first->equals($second))->toBeTrue();
    expect($first->equals($third))->toBeFalse();
});

it('performs strict type comparison in isIn method', function () {
    $mimeType = new MimeType('text/plain');

    expect($mimeType->isIn(['text/plain']))->toBeTrue();
    expect($mimeType->isIn([0 => 'text/plain']))->toBeTrue();
});
