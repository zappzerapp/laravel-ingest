<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow;

use Flow\ETL\DataFrame;
use Flow\ETL\Memory\ArrayMemory;

use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\from_array;
use function Flow\ETL\DSL\lit;
use function Flow\ETL\DSL\ref;
use function Flow\ETL\DSL\to_memory;

it('can create and execute a basic data frame', function () {
    $memory = new ArrayMemory();

    $data = [
        ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25],
        ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 30],
        ['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35],
    ];

    data_frame()
        ->read(from_array($data))
        ->write(to_memory($memory))
        ->run();

    expect($memory->count())->toBe(3);

    $result = $memory->dump();
    expect($result)->toHaveCount(3);
    expect($result[0])->toHaveKey('name', 'Alice');
    expect($result[0])->toHaveKey('email', 'alice@example.com');
    expect($result[0])->toHaveKey('age', 25);
});

it('can transform data in a data frame', function () {
    $memory = new ArrayMemory();

    $data = [
        ['name' => 'alice', 'age' => 25],
        ['name' => 'bob', 'age' => 30],
    ];

    data_frame()
        ->read(from_array($data))
        ->withEntry('age_plus_10', ref('age')->plus(lit(10)))
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0])->toHaveKey('age_plus_10');
    expect($result[0]['age_plus_10'])->toBe(35);
    expect($result[1]['age_plus_10'])->toBe(40);
});

it('can use createDataFrame helper directly', function () {
    $data = [
        ['id' => 1, 'value' => 'test'],
    ];

    $df = data_frame()->read(from_array($data));
    expect($df)->toBeInstanceOf(DataFrame::class);

    $memory = new ArrayMemory();
    $df->write(to_memory($memory))->run();
    $result = $memory->dump();

    expect($result)->toHaveCount(1);
    expect($result[0])->toHaveKey('id', 1);
});

it('can load fixtures from CSV file', function () {
    $path = __DIR__ . '/../../fixtures/users.csv';
    expect(file_exists($path))->toBeTrue();

    $handle = fopen($path, 'r');
    $headers = fgetcsv($handle);
    $data = [];
    while (($row = fgetcsv($handle)) !== false) {
        $data[] = array_combine($headers, $row);
    }
    fclose($handle);

    expect($data)->toHaveCount(3);
    expect($data[0])->toHaveKey('name');
    expect($data[0])->toHaveKey('email');
    expect($data[0])->toHaveKey('age');

    $df = data_frame()->read(from_array($data));
    $memory = new ArrayMemory();
    $df->write(to_memory($memory))->run();
    $result = $memory->dump();

    expect($result)->toHaveCount(3);
});

it('can load fixtures from JSON file', function () {
    $path = __DIR__ . '/../../fixtures/users.json';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    $data = json_decode($content, true);

    expect($data)->toHaveCount(3);
    expect($data[0])->toHaveKey('name', 'Alice');
    expect($data[0])->toHaveKey('email', 'alice@example.com');
    expect($data[0])->toHaveKey('age', 25);

    $df = data_frame()->read(from_array($data));
    $memory = new ArrayMemory();
    $df->write(to_memory($memory))->run();
    $result = $memory->dump();

    expect($result)->toHaveCount(3);
    expect($result[0])->toHaveKey('name', 'Alice');
});
