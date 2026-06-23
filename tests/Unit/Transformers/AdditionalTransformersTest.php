<?php

declare(strict_types=1);

use LaravelIngest\Transformers\ConcatTransformer;
use LaravelIngest\Transformers\DefaultValueTransformer;
use LaravelIngest\Transformers\MapTransformer;
use LaravelIngest\Transformers\SlugTransformer;
use LaravelIngest\Transformers\TrimTransformer;

it('trims whitespace from strings', function () {
    $transformer = new TrimTransformer();

    expect($transformer->transform('  hello  ', []))->toBe('hello')
        ->and($transformer->transform("\t\nhello\r\n", []))->toBe('hello')
        ->and($transformer->transform('hello', []))->toBe('hello');
});

it('trims with custom character mask', function () {
    $transformer = new TrimTransformer('x');

    expect($transformer->transform('xxhelloxx', []))->toBe('hello');
});

it('returns null for null trim input', function () {
    $transformer = new TrimTransformer();

    expect($transformer->transform(null, []))->toBeNull();
});

it('creates slug from string', function () {
    $transformer = new SlugTransformer();

    expect($transformer->transform('Hello World', []))->toBe('hello-world')
        ->and($transformer->transform('Multiple   Spaces', []))->toBe('multiple-spaces')
        ->and($transformer->transform('Special!@#Characters', []))->toBe('special-characters');
});

it('creates slug with custom separator', function () {
    $transformer = new SlugTransformer('_');

    expect($transformer->transform('Hello World', []))->toBe('hello_world');
});

it('returns null for empty slug input', function () {
    $transformer = new SlugTransformer();

    expect($transformer->transform('', []))->toBeNull()
        ->and($transformer->transform(null, []))->toBeNull();
});

it('maps values using lookup table', function () {
    $transformer = new MapTransformer([
        'active' => 1,
        'inactive' => 0,
    ]);

    expect($transformer->transform('active', []))->toBe(1)
        ->and($transformer->transform('inactive', []))->toBe(0);
});

it('returns default for unmapped values', function () {
    $transformer = new MapTransformer(['a' => 1], 999);

    expect($transformer->transform('unknown', []))->toBe(999);
});

it('returns default for null map input', function () {
    $transformer = new MapTransformer(['key' => 'value'], 'default');

    expect($transformer->transform(null, []))->toBe('default')
        ->and($transformer->transform('', []))->toBe('default');
});

it('concatenates multiple fields', function () {
    $transformer = new ConcatTransformer(['first_name', 'last_name'], ' ');

    $result = $transformer->transform(null, [
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    expect($result)->toBe('John Doe');
});

it('skips null values in concat', function () {
    $transformer = new ConcatTransformer(['a', 'b', 'c'], '-');

    $result = $transformer->transform(null, [
        'a' => 'first',
        'b' => null,
        'c' => 'last',
    ]);

    expect($result)->toBe('first-last');
});

it('returns null when all concat fields are empty', function () {
    $transformer = new ConcatTransformer(['a', 'b']);

    $result = $transformer->transform(null, [
        'a' => null,
        'b' => '',
    ]);

    expect($result)->toBeNull();
});

it('uses default value for empty input', function () {
    $transformer = new DefaultValueTransformer('N/A');

    expect($transformer->transform(null, []))->toBe('N/A')
        ->and($transformer->transform('', []))->toBe('N/A')
        ->and($transformer->transform('value', []))->toBe('value');
});

it('uses custom empty values list', function () {
    $transformer = new DefaultValueTransformer('N/A', ['null', 'NULL']);

    expect($transformer->transform('null', []))->toBe('N/A')
        ->and($transformer->transform('NULL', []))->toBe('N/A')
        ->and($transformer->transform('', []))->toBe('');
});
