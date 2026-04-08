<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow\Transformers;

use DateTime;
use Flow\ETL\Memory\ArrayMemory;
use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Flow\Transformers\CallbackTransformer;
use RuntimeException;

use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\from_array;
use function Flow\ETL\DSL\to_memory;

it('implements Flow ETL Transformation interface', function () {
    $callback = new SerializableClosure(fn(array $row) => $row);
    $transformer = new CallbackTransformer($callback);

    expect($transformer)->toBeInstanceOf(\Flow\ETL\Transformation::class);
});

it('executes beforeRow callback on each row', function () {
    $memory = new ArrayMemory();

    $data = [
        ['name' => 'Alice', 'age' => 25],
        ['name' => 'Bob', 'age' => 30],
    ];

    $callback = new SerializableClosure(fn(array $row) => array_merge($row, ['processed' => true]));
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result)->toHaveCount(2);
    expect($result[0])->toHaveKey('processed', true);
    expect($result[1])->toHaveKey('processed', true);
});

it('allows callback to modify row data', function () {
    $memory = new ArrayMemory();

    $data = [
        ['name' => 'alice', 'age' => 25],
        ['name' => 'bob', 'age' => 30],
    ];

    $callback = new SerializableClosure(function (array $row) {
        $row['name'] = strtoupper($row['name']);
        $row['age'] = $row['age'] + 10;

        return $row;
    });

    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['name'])->toBe('ALICE');
    expect($result[0]['age'])->toBe(35);
    expect($result[1]['name'])->toBe('BOB');
    expect($result[1]['age'])->toBe(40);
});

it('marks row as failed when callback throws exception', function () {
    $memory = new ArrayMemory();
    $errorsMemory = new ArrayMemory();

    $data = [
        ['name' => 'Alice', 'age' => 25],
        ['name' => 'Bob', 'age' => 30],
        ['name' => 'Charlie', 'age' => 35],
    ];

    $callback = new SerializableClosure(function (array $row) {
        if ($row['name'] === 'Bob') {
            throw new RuntimeException('Invalid name: Bob');
        }

        return $row;
    });

    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();

    // Should have all 3 rows - pipeline should not crash
    expect($result)->toHaveCount(3);

    // Check that Alice and Charlie succeeded
    expect($result[0])->toHaveKey('name', 'Alice');
    expect($result[0])->not->toHaveKey('_error');
    expect($result[2])->toHaveKey('name', 'Charlie');
    expect($result[2])->not->toHaveKey('_error');

    // Check that Bob has error marked
    expect($result[1])->toHaveKey('_error');
    expect($result[1]['_error'])->toContain('Invalid name: Bob');
});

it('continues pipeline when callback fails for some rows', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'value' => 'first'],
        ['id' => 2, 'value' => 'second'],
        ['id' => 3, 'value' => 'third'],
    ];

    $processedIds = [];
    $callback = new SerializableClosure(function (array $row) use (&$processedIds) {
        $processedIds[] = $row['id'];
        if ($row['id'] === 2) {
            throw new RuntimeException('Skip row 2');
        }

        return $row;
    });

    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    // All rows should be processed
    expect($processedIds)->toBe([1, 2, 3]);

    // Result should have all 3 rows
    $result = $memory->dump();
    expect($result)->toHaveCount(3);
});

it('preserves original row data when callback makes no changes', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'name' => 'Test', 'nested' => ['key' => 'value']],
    ];

    $callback = new SerializableClosure(fn(array $row) => $row);
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['id'])->toBe(1);
    expect($result[0]['name'])->toBe('Test');
    expect($result[0]['nested'])->toBe(['key' => 'value']);
});

it('transforms row with integer values', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'count' => 100],
    ];

    $callback = new SerializableClosure(fn(array $row) => array_merge($row, ['doubled' => $row['count'] * 2]));
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['count'])->toBe(100);
    expect($result[0]['doubled'])->toBe(200);
});

it('transforms row with boolean values', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'active' => true],
    ];

    $callback = new SerializableClosure(fn(array $row) => array_merge($row, ['inactive' => !$row['active']]));
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['active'])->toBe(true);
    expect($result[0]['inactive'])->toBe(false);
});

it('transforms row with null values', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'name' => null],
    ];

    $callback = new SerializableClosure(fn(array $row) => array_merge($row, ['has_name' => $row['name'] !== null]));
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['name'])->toBeNull();
    expect($result[0]['has_name'])->toBe(false);
});

it('transforms row with date values', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'created_at' => '2024-01-15'],
    ];

    $callback = new SerializableClosure(function (array $row) {
        $row['year'] = substr($row['created_at'], 0, 4);

        return $row;
    });
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['created_at'])->toBe('2024-01-15');
    expect($result[0]['year'])->toBe('2024');
});

it('transforms row with nested array values', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'items' => ['a', 'b', 'c']],
    ];

    $callback = new SerializableClosure(fn(array $row) => array_merge($row, ['item_count' => count($row['items'])]));
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['item_count'])->toBe(3);
});

it('transforms row with float values', function () {
    $memory = new ArrayMemory();

    $data = [
        ['id' => 1, 'price' => 19.99],
    ];

    $callback = new SerializableClosure(fn(array $row) => array_merge($row, ['price_with_tax' => $row['price'] * 1.2]));
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['price'])->toBe(19.99);
    expect($result[0]['price_with_tax'])->toBe(23.988);
});

it('transforms row with datetime values', function () {
    $memory = new ArrayMemory();

    $dateTime = new DateTime('2024-06-15 10:30:00');
    $data = [
        ['id' => 1, 'created_at' => $dateTime],
    ];

    $callback = new SerializableClosure(function (array $row) {
        $row['date_string'] = $row['created_at']->format('Y-m-d H:i:s');

        return $row;
    });
    $transformer = new CallbackTransformer($callback);

    data_frame()
        ->read(from_array($data))
        ->transform($transformer)
        ->write(to_memory($memory))
        ->run();

    $result = $memory->dump();
    expect($result[0]['date_string'])->toBe('2024-06-15 10:30:00');
});
