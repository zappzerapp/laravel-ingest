<?php

declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\DataTransformationService;

beforeEach(function () {
    IngestRow::query()->delete();
    IngestRun::query()->delete();
});

it('processes mapping with nonexistent source field', function () {
    $service = new DataTransformationService();

    $data = ['name' => 'John'];
    $mappings = [
        'nonexistent' => ['attribute' => 'full_name'],
    ];

    $result = $service->processMappings($data, $mappings);

    expect($result)->toBeArray();
    expect($result)->toBeEmpty();
});

it('processes mapping with nested key', function () {
    $service = new DataTransformationService();

    $data = ['user' => ['name' => 'John']];
    $mappings = [
        'user.name' => ['attribute' => 'full_name'],
    ];

    $result = $service->processMappings($data, $mappings);

    expect($result['full_name'])->toBe('John');
});

it('processes mapping with transformer', function () {
    $service = new DataTransformationService();

    $data = ['name' => 'John'];
    $transformer = new SerializableClosure(fn($value) => strtoupper($value));

    $mappings = [
        'name' => [
            'attribute' => 'name_upper',
            'transformer' => $transformer,
        ],
    ];

    $result = $service->processMappings($data, $mappings);

    expect($result['name_upper'])->toBe('JOHN');
});

it('processes relations found in cache', function () {
    $service = new DataTransformationService();
    $run = IngestRun::factory()->create();

    $processedData = ['run_id' => 999];

    $relations = [
        'run_id' => [
            'relation' => 'ingestRun',
            'model' => IngestRun::class,
            'key' => 'id',
            'createIfMissing' => false,
        ],
    ];

    $relationCache = [
        'run_id' => [
            999 => $run->id,
        ],
    ];

    $result = $service->processRelations(
        $processedData,
        $relations,
        $relationCache,
        IngestRow::class
    );

    expect($result)->toHaveKey('ingest_run_id');
    expect($result['ingest_run_id'])->toBe($run->id);
});

it('processes relations creating missing', function () {
    $service = new DataTransformationService();

    $processedData = ['run_id' => 999];

    $relations = [
        'run_id' => [
            'relation' => 'ingestRun',
            'model' => IngestRun::class,
            'key' => 'id',
            'createIfMissing' => true,
        ],
    ];

    $relationCache = [];

    $relations['run_id']['key'] = 'importer';
    $processedData['run_id'] = 'new_importer';

    $result = $service->processRelations(
        $processedData,
        $relations,
        $relationCache,
        IngestRow::class
    );

    $newRun = IngestRun::where('importer', 'new_importer')->first();

    expect($newRun)->not->toBeNull();
    expect($result['ingest_run_id'])->toBe($newRun->id);
});

it('processes unmapped data', function () {
    $service = new DataTransformationService();

    $processedData = [
        'mapped_field' => 'value',
        'status' => 'pending',
        'row_number' => 123,
        'unknown_field' => 'ignore me',
    ];

    $mappings = ['mapped_field' => []];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        IngestRow::class
    );

    expect($result)->toHaveKey('status');
    expect($result['status'])->toBe('pending');
    expect($result)->toHaveKey('row_number');
    expect($result['row_number'])->toBe(123);

    expect($result)->toHaveKey('unknown_field');
});

it('processes relations with missing source field', function () {
    $service = new DataTransformationService();

    $processedData = ['some_field' => 'value'];
    $relations = [
        'run_id' => [
            'relation' => 'ingestRun',
            'model' => IngestRun::class,
            'key' => 'id',
            'createIfMissing' => false,
        ],
    ];

    $relationCache = [];

    $result = $service->processRelations(
        $processedData,
        $relations,
        $relationCache,
        IngestRow::class
    );

    expect($result)->toBeEmpty();
});

it('handles nested key failure safely', function () {
    $service = new DataTransformationService();

    $data = ['user' => 'string_not_array'];

    $mappings = [
        'user.name' => ['attribute' => 'full_name'],
    ];

    $result = $service->processMappings($data, $mappings);

    expect($result)->not->toHaveKey('full_name');
});
