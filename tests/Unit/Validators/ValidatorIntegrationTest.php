<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\Transformers\NumericTransformer;
use LaravelIngest\Validators\EmailValidator;
use LaravelIngest\Validators\RangeValidator;
use LaravelIngest\Validators\RequiredValidator;

it('accepts validator class string in mapAndValidate', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndValidate('email', 'email', EmailValidator::class);

    expect($config->validators['email']['validators'][0])
        ->toBeInstanceOf(EmailValidator::class);
});

it('accepts validator instance in mapAndValidate', function () {
    $validator = new RequiredValidator();

    $config = IngestConfig::for(Product::class)
        ->mapAndValidate('name', 'product_name', $validator);

    expect($config->validators['name']['validators'][0])
        ->toBe($validator);
});

it('accepts array of validators in mapAndValidate', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndValidate('price', 'price', [
            RequiredValidator::class,
            new RangeValidator(min: 0),
        ]);

    expect($config->validators['price']['validators'])
        ->toHaveCount(2);
});

it('creates mapping entry when using mapAndValidate', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndValidate('sku', 'sku', RequiredValidator::class);

    expect($config->mappings)
        ->toHaveKey('sku')
        ->and($config->mappings['sku']['attribute'])
        ->toBe('sku');
});

it('validates row data and returns errors', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndValidate('email', 'email', EmailValidator::class)
        ->mapAndValidate('price', 'price', [
            RequiredValidator::class,
            new RangeValidator(min: 0),
        ]);

    $errors = $config->validateRow([
        'email' => 'invalid-email',
        'price' => -10,
    ]);

    expect($errors)->toHaveKey('email')
        ->and($errors)->toHaveKey('price')
        ->and($errors['email'])->toContain('Invalid email format');
});

it('returns no errors for valid data', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndValidate('email', 'email', EmailValidator::class)
        ->mapAndValidate('price', 'price', new RangeValidator(min: 0));

    $errors = $config->validateRow([
        'email' => 'test@example.com',
        'price' => 100,
    ]);

    expect($errors)->toBeEmpty();
});

it('validates only existing validators', function () {
    $config = IngestConfig::for(Product::class)
        ->mapAndValidate('name', 'name', RequiredValidator::class);

    $errors = $config->validateRow([]);

    expect($errors['name'])->toContain('Field is required');
});

it('supports mapTransformAndValidate', function () {
    $config = IngestConfig::for(Product::class)
        ->mapTransformAndValidate(
            'price_cents',
            'price',
            [new NumericTransformer(decimals: 2)],
            [new RangeValidator(min: 0)]
        );

    expect($config->mappings)->toHaveKey('price_cents')
        ->and($config->validators)->toHaveKey('price_cents')
        ->and($config->mappings['price_cents']['transformers'])->toHaveCount(1)
        ->and($config->validators['price_cents']['validators'])->toHaveCount(1);
});

it('throws exception for non-existent validator class', function () {
    IngestConfig::for(Product::class)
        ->mapAndValidate('field', 'attr', 'NonExistentValidator');
})->throws(LaravelIngest\Exceptions\InvalidConfigurationException::class);

it('throws exception for class not implementing ValidatorInterface', function () {
    IngestConfig::for(Product::class)
        ->mapAndValidate('field', 'attr', stdClass::class);
})->throws(LaravelIngest\Exceptions\InvalidConfigurationException::class);
