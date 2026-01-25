<?php

declare(strict_types=1);

use LaravelIngest\ValueObjects\FileSize;

it('throws exception for negative bytes', function () {
    new FileSize(-100);
})->throws(InvalidArgumentException::class, 'File size cannot be negative');

it('converts bytes to megabytes', function () {
    $fileSize = new FileSize(1024 * 1024); // 1 MB

    expect($fileSize->inMegabytes())->toBe(1.0);
});

it('converts bytes to megabytes with decimal precision', function () {
    $fileSize = new FileSize(512 * 1024); // 0.5 MB

    expect($fileSize->inMegabytes())->toBe(0.5);
});

it('determines if file size exceeds another', function () {
    $smaller = new FileSize(1024);
    $larger = new FileSize(2048);

    expect($larger->exceeds($smaller))->toBeTrue()
        ->and($smaller->exceeds($larger))->toBeFalse();
});

it('returns false when file sizes are equal', function () {
    $first = new FileSize(1024);
    $second = new FileSize(1024);

    expect($first->exceeds($second))->toBeFalse();
});

it('converts to string representation', function () {
    $fileSize = new FileSize(1024 * 1024); // 1 MB

    expect($fileSize->toString())->toBe('1 MB');
});

it('converts to string with decimal megabytes', function () {
    $fileSize = new FileSize(512 * 1024); // 0.5 MB

    expect($fileSize->toString())->toBe('0.5 MB');
});
