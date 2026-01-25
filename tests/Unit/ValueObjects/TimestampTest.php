<?php

declare(strict_types=1);

use DateTime;
use LaravelIngest\ValueObjects\Timestamp;

it('creates timestamp with null value', function () {
    $timestamp = new Timestamp(null);

    expect($timestamp->value)->toBeNull();
});

it('creates timestamp with string value', function () {
    $timestamp = new Timestamp('2023-01-01 12:00:00');

    expect($timestamp->value)->toBe('2023-01-01 12:00:00');
});

it('creates timestamp with DateTime value', function () {
    $dateTime = new DateTime('2023-01-01 12:00:00');
    $timestamp = new Timestamp($dateTime);

    expect($timestamp->value)->toBe($dateTime);
});

it('returns false for null value in toUnixTimestamp', function () {
    $timestamp = new Timestamp(null);

    expect($timestamp->toUnixTimestamp())->toBeFalse();
});

it('converts DateTime to unix timestamp', function () {
    $dateTime = new DateTime('2023-01-01 12:00:00');
    $timestamp = new Timestamp($dateTime);

    expect($timestamp->toUnixTimestamp())->toBe($dateTime->getTimestamp());
});

it('converts string to unix timestamp', function () {
    $timestamp = new Timestamp('2023-01-01 12:00:00');
    $expected = strtotime('2023-01-01 12:00:00');

    expect($timestamp->toUnixTimestamp())->toBe($expected);
});

it('returns false when comparing null timestamps', function () {
    $first = new Timestamp(null);
    $second = new Timestamp(null);

    expect($first->isNewerThan($second))->toBeFalse();
});

it('returns false when one timestamp is null', function () {
    $valid = new Timestamp('2023-01-01 12:00:00');
    $null = new Timestamp(null);

    expect($valid->isNewerThan($null))->toBeFalse();
    expect($null->isNewerThan($valid))->toBeFalse();
});

it('determines if timestamp is newer than another', function () {
    $older = new Timestamp('2023-01-01 12:00:00');
    $newer = new Timestamp('2023-01-02 12:00:00');

    expect($newer->isNewerThan($older))->toBeTrue();
    expect($older->isNewerThan($newer))->toBeFalse();
});

it('returns false when timestamps are equal', function () {
    $first = new Timestamp('2023-01-01 12:00:00');
    $second = new Timestamp('2023-01-01 12:00:00');

    expect($first->isNewerThan($second))->toBeFalse();
});

it('checks if timestamp is null', function () {
    $nullTimestamp = new Timestamp(null);
    $validTimestamp = new Timestamp('2023-01-01 12:00:00');

    expect($nullTimestamp->isNull())->toBeTrue();
    expect($validTimestamp->isNull())->toBeFalse();
});

it('handles invalid date string in toUnixTimestamp', function () {
    $timestamp = new Timestamp('invalid date string');

    expect($timestamp->toUnixTimestamp())->toBeFalse();
});

it('returns false when comparing with invalid timestamp', function () {
    $valid = new Timestamp('2023-01-01 12:00:00');
    $invalid = new Timestamp('invalid date');

    expect($valid->isNewerThan($invalid))->toBeFalse();
    expect($invalid->isNewerThan($valid))->toBeFalse();
});
