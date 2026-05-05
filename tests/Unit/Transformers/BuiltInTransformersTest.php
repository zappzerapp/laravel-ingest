<?php

declare(strict_types=1);

use LaravelIngest\Transformers\BooleanTransformer;
use LaravelIngest\Transformers\DateTransformer;
use LaravelIngest\Transformers\NumericTransformer;

it('transforms truthy values to 1', function () {
    $transformer = new BooleanTransformer();

    expect($transformer->transform('yes', []))->toBe(1)
        ->and($transformer->transform('true', []))->toBe(1)
        ->and($transformer->transform('1', []))->toBe(1)
        ->and($transformer->transform('on', []))->toBe(1)
        ->and($transformer->transform('y', []))->toBe(1)
        ->and($transformer->transform('YES', []))->toBe(1);
});

it('transforms falsy values to 0', function () {
    $transformer = new BooleanTransformer();

    expect($transformer->transform('no', []))->toBe(0)
        ->and($transformer->transform('false', []))->toBe(0)
        ->and($transformer->transform('0', []))->toBe(0)
        ->and($transformer->transform('off', []))->toBe(0)
        ->and($transformer->transform('n', []))->toBe(0)
        ->and($transformer->transform('NO', []))->toBe(0);
});

it('returns default for unmatched values', function () {
    $transformer = new BooleanTransformer(default: null);

    expect($transformer->transform('maybe', []))->toBeNull()
        ->and($transformer->transform('', []))->toBeNull()
        ->and($transformer->transform(null, []))->toBeNull();
});

it('respects case sensitivity when configured', function () {
    $transformer = new BooleanTransformer(caseSensitive: true);

    expect($transformer->transform('yes', []))->toBe(1)
        ->and($transformer->transform('YES', []))->toBeNull()
        ->and($transformer->transform('Yes', []))->toBeNull();
});

it('allows custom truthy and falsy values', function () {
    $transformer = new BooleanTransformer(
        truthyValues: ['ja', 'si'],
        falsyValues: ['nein', 'no'],
        default: 0
    );

    expect($transformer->transform('ja', []))->toBe(1)
        ->and($transformer->transform('nein', []))->toBe(0)
        ->and($transformer->transform('yes', []))->toBe(0);
});

it('converts string to numeric value', function () {
    $transformer = new NumericTransformer();

    expect($transformer->transform('123.45', []))->toBe(123.45)
        ->and($transformer->transform('100', []))->toBe(100.0);
});

it('handles thousands separator', function () {
    $transformer = new NumericTransformer(thousandsSeparator: ',');

    expect($transformer->transform('1,234.56', []))->toBe(1234.56);
});

it('handles european number format', function () {
    $transformer = new NumericTransformer(
        decimalSeparator: ',',
        thousandsSeparator: '.'
    );

    expect($transformer->transform('1.234,56', []))->toBe(1234.56);
});

it('rounds to specified decimal places', function () {
    $transformer = new NumericTransformer(decimals: 2);

    expect($transformer->transform('123.456', []))->toBe(123.46)
        ->and($transformer->transform('123.454', []))->toBe(123.45);
});

it('enforces min and max constraints', function () {
    $transformer = new NumericTransformer(min: 0, max: 100);

    expect($transformer->transform('-10', []))->toBe(0.0)
        ->and($transformer->transform('150', []))->toBe(100.0)
        ->and($transformer->transform('50', []))->toBe(50.0);
});

it('returns default for non-numeric values', function () {
    $transformer = new NumericTransformer(default: 0);

    expect($transformer->transform('abc', []))->toBe(0)
        ->and($transformer->transform('', []))->toBe(0)
        ->and($transformer->transform(null, []))->toBe(0);
});

it('formats date from one format to another', function () {
    $transformer = new DateTransformer(
        inputFormat: 'd/m/Y',
        outputFormat: 'Y-m-d'
    );

    expect($transformer->transform('15/03/2024', []))->toBe('2024-03-15');
});

it('handles DateTimeInterface input', function () {
    $transformer = new DateTransformer(outputFormat: 'Y-m-d H:i:s');
    $date = new DateTimeImmutable('2024-03-15 14:30:00');

    expect($transformer->transform($date, []))->toBe('2024-03-15 14:30:00');
});

it('returns default for invalid dates', function () {
    $transformer = new DateTransformer(default: '1970-01-01');

    expect($transformer->transform('invalid', []))->toBe('1970-01-01')
        ->and($transformer->transform('', []))->toBe('1970-01-01')
        ->and($transformer->transform(null, []))->toBe('1970-01-01');
});

it('passes row context to transformers', function () {
    $transformer = new NumericTransformer();
    $rowContext = ['currency' => 'EUR', 'price' => '100.00'];

    expect($transformer->transform('50', $rowContext))->toBe(50.0);
});

it('can be instantiated with default values', function () {
    $transformer = new BooleanTransformer();

    expect($transformer->transform(null, []))->toBeNull();
});
