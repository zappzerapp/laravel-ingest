<?php

declare(strict_types=1);

use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Validators\DateValidator;
use LaravelIngest\Validators\EmailValidator;
use LaravelIngest\Validators\InArrayValidator;
use LaravelIngest\Validators\RangeValidator;
use LaravelIngest\Validators\RegexValidator;
use LaravelIngest\Validators\RequiredValidator;

it('passes validation for non-empty values', function () {
    $validator = new RequiredValidator();

    expect($validator->validate('value', [])->passed())->toBeTrue()
        ->and($validator->validate(0, [])->passed())->toBeTrue()
        ->and($validator->validate(false, [])->passed())->toBeTrue()
        ->and($validator->validate('0', [])->passed())->toBeTrue();
});

it('fails validation for null', function () {
    $validator = new RequiredValidator();
    $result = $validator->validate(null, []);

    expect($result->failed())->toBeTrue()
        ->and($result->errors())->toContain('Field is required');
});

it('fails validation for empty string', function () {
    $validator = new RequiredValidator();
    $result = $validator->validate('', []);

    expect($result->failed())->toBeTrue();
});

it('fails validation for empty array', function () {
    $validator = new RequiredValidator();
    $result = $validator->validate([], []);

    expect($result->failed())->toBeTrue();
});

it('validates email format', function () {
    $validator = new EmailValidator();

    expect($validator->validate('test@example.com', [])->passed())->toBeTrue()
        ->and($validator->validate('user.name+tag@example.co.uk', [])->passed())->toBeTrue()
        ->and($validator->validate('invalid-email', [])->failed())->toBeTrue()
        ->and($validator->validate('test@', [])->failed())->toBeTrue();
});

it('allows null and empty email values', function () {
    $validator = new EmailValidator();

    expect($validator->validate(null, [])->passed())->toBeTrue()
        ->and($validator->validate('', [])->passed())->toBeTrue();
});

it('allows null and empty values in range validator', function () {
    $validator = new RangeValidator(min: 0, max: 100);

    expect($validator->validate(null, [])->passed())->toBeTrue()
        ->and($validator->validate('', [])->passed())->toBeTrue();
});

it('validates numeric range', function () {
    $validator = new RangeValidator(min: 0, max: 100);

    expect($validator->validate(50, [])->passed())->toBeTrue()
        ->and($validator->validate(0, [])->passed())->toBeTrue()
        ->and($validator->validate(100, [])->passed())->toBeTrue()
        ->and($validator->validate(-1, [])->failed())->toBeTrue()
        ->and($validator->validate(101, [])->failed())->toBeTrue();
});

it('allows only min in range validator', function () {
    $validator = new RangeValidator(min: 10);

    expect($validator->validate(10, [])->passed())->toBeTrue()
        ->and($validator->validate(5, [])->failed())->toBeTrue()
        ->and($validator->validate(1000, [])->passed())->toBeTrue();
});

it('allows only max in range validator', function () {
    $validator = new RangeValidator(max: 100);

    expect($validator->validate(100, [])->passed())->toBeTrue()
        ->and($validator->validate(101, [])->failed())->toBeTrue()
        ->and($validator->validate(-1000, [])->passed())->toBeTrue();
});

it('fails on non-numeric value in range validator', function () {
    $validator = new RangeValidator(min: 0, max: 100);
    $result = $validator->validate('not-a-number', []);

    expect($result->failed())->toBeTrue()
        ->and($result->firstError())->toBe('Value must be numeric');
});

it('validates with custom error message', function () {
    $validator = new RangeValidator(min: 0, max: 100, message: 'Price out of bounds');
    $result = $validator->validate(200, []);

    expect($result->firstError())->toBe('Price out of bounds');
});

it('uses default error message for min validation', function () {
    $validator = new RangeValidator(min: 10);
    $result = $validator->validate(5, []);

    expect($result->firstError())->toBe('Value must be at least 10');
});

it('uses default error message for max validation', function () {
    $validator = new RangeValidator(max: 100);
    $result = $validator->validate(200, []);

    expect($result->firstError())->toBe('Value must be at most 100');
});

it('validates with regex pattern', function () {
    $validator = new RegexValidator('/^\d{5}$/', 'Must be 5 digits');

    expect($validator->validate('12345', [])->passed())->toBeTrue()
        ->and($validator->validate('1234', [])->failed())->toBeTrue()
        ->and($validator->validate('123456', [])->failed())->toBeTrue()
        ->and($validator->validate('abcde', [])->failed())->toBeTrue();
});

it('allows null and empty values in regex validator', function () {
    $validator = new RegexValidator('/^[a-z]+$/');

    expect($validator->validate(null, [])->passed())->toBeTrue()
        ->and($validator->validate('', [])->passed())->toBeTrue();
});

it('validates date format', function () {
    $validator = new DateValidator('Y-m-d');

    expect($validator->validate('2024-03-15', [])->passed())->toBeTrue()
        ->and($validator->validate('2024-13-45', [])->failed())->toBeTrue()
        ->and($validator->validate('15-03-2024', [])->failed())->toBeTrue();
});

it('validates custom date format', function () {
    $validator = new DateValidator('d/m/Y');

    expect($validator->validate('15/03/2024', [])->passed())->toBeTrue()
        ->and($validator->validate('2024-03-15', [])->failed())->toBeTrue();
});

it('allows null and empty date values', function () {
    $validator = new DateValidator();

    expect($validator->validate(null, [])->passed())->toBeTrue()
        ->and($validator->validate('', [])->passed())->toBeTrue();
});

it('validates value is in allowed array', function () {
    $validator = new InArrayValidator(['active', 'inactive', 'pending']);

    expect($validator->validate('active', [])->passed())->toBeTrue()
        ->and($validator->validate('inactive', [])->passed())->toBeTrue()
        ->and($validator->validate('deleted', [])->failed())->toBeTrue();
});

it('validates strict mode for in array validator', function () {
    $validator = new InArrayValidator(['1', '2', '3'], strict: true);

    expect($validator->validate('1', [])->passed())->toBeTrue()
        ->and($validator->validate(1, [])->failed())->toBeTrue();
});

it('allows null and empty values in in-array validator', function () {
    $validator = new InArrayValidator(['a', 'b', 'c']);

    expect($validator->validate(null, [])->passed())->toBeTrue()
        ->and($validator->validate('', [])->passed())->toBeTrue();
});

it('returns all validation errors', function () {
    $result = ValidationResult::fail(['Error 1', 'Error 2', 'Error 3']);

    expect($result->errors())->toHaveCount(3)
        ->and($result->firstError())->toBe('Error 1');
});

it('can create single error validation result', function () {
    $result = ValidationResult::fail('Single error');

    expect($result->errors())->toHaveCount(1)
        ->and($result->firstError())->toBe('Single error');
});

it('validation result has no errors on pass', function () {
    $result = ValidationResult::pass();

    expect($result->errors())->toBeEmpty()
        ->and($result->firstError())->toBeNull();
});
