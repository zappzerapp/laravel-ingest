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

    expect($result)->toBeArray()
        ->and($result)->toBeEmpty();
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

    expect($result)->toHaveKey('ingest_run_id')
        ->and($result['ingest_run_id'])->toBe($run->id);
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

    expect($newRun)->not->toBeNull()
        ->and($result['ingest_run_id'])->toBe($newRun->id);
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

it('filters unmapped data for non-fillable attributes', function () {
    $service = new DataTransformationService();

    // Product model has guarded fields
    $processedData = ['sku' => 'ABC123', 'name' => 'Product', 'fake_column' => 'value'];
    $mappings = ['sku' => ['attribute' => 'sku']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        LaravelIngest\Tests\Fixtures\Models\Product::class
    );

    // name and fake_column should not be included since Product has guarded=['*']
    // Only columns that exist in the database are included when guarded=['*']
    expect($result)->not->toHaveKey('fake_column');
});

it('filters unmapped data for guarded models by checking database columns', function () {
    $service = new DataTransformationService();

    // User model has guarded=[] (all fillable)
    $processedData = ['email' => 'test@example.com', 'nonexistent_column' => 'value'];
    $mappings = [];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        LaravelIngest\Tests\Fixtures\Models\User::class
    );

    // User has guarded=[], so all attributes are fillable
    // But the code should filter out columns that don't exist in the database
    expect($result)->not->toHaveKey('nonexistent_column')
        ->and($result)->toHaveKey('email');
});

it('includes unmapped data when model has partial guarded', function () {
    $service = new DataTransformationService();

    // Assuming User model - let's test with a real column
    $processedData = ['name' => 'Test', 'email' => 'test@example.com'];
    $mappings = ['name' => ['attribute' => 'name']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        LaravelIngest\Tests\Fixtures\Models\User::class
    );

    // email is unmapped - should be included (fillable and valid column)
    expect($result)->toHaveKey('email');
});

it('excludes non-fillable attributes from unmapped data', function () {
    $service = new DataTransformationService();

    // ProductWithCategory has guarded fields (not all fillable)
    $processedData = ['name' => 'Product', 'category_id' => 1, 'non_existent' => 'value'];
    $mappings = ['name' => ['attribute' => 'name']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        LaravelIngest\Tests\Fixtures\Models\ProductWithCategory::class
    );

    // category_id should be excluded if not fillable
    // The model has guarded = ['id'] so most fields should be fillable
    // But non_existent should be filtered by the column check
    expect($result)->not->toHaveKey('non_existent');
});

it('falls back to allowing all fields when Schema throws exception', function () {
    $service = new DataTransformationService();

    // Temporarily mock Schema to throw
    Illuminate\Support\Facades\Schema::shouldReceive('getColumnListing')
        ->andThrow(new Exception('Database error'));

    $processedData = ['email' => 'test@example.com', 'name' => 'Test'];
    $mappings = [];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    // User has guarded = [] so it will try to check columns
    // When Schema throws, it should fall back to returning true (allowing the field)
    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        LaravelIngest\Tests\Fixtures\Models\User::class
    );

    // Both should be present since Schema exception triggers fallback
    expect($result)->toHaveKey('email')
        ->and($result)->toHaveKey('name');

    // Clear the mock
    Illuminate\Support\Facades\Schema::partialMock();
});

it('returns true for fillable fields when model has specific guarded', function () {
    $service = new DataTransformationService();

    // ProductWithCategory has guarded = [] (all fillable)
    // When isFillable returns true, it continues to check column existence
    $processedData = ['name' => 'Product', 'category_id' => 1];
    $mappings = ['name' => ['attribute' => 'name']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        LaravelIngest\Tests\Fixtures\Models\ProductWithCategory::class
    );

    expect($result)->toHaveKey('category_id');
});

it('excludes non-fillable attributes from unmapped data for models with explicit fillable', function () {
    $service = new DataTransformationService();

    $processedData = ['email' => 'test@example.com', 'name' => 'Test', 'password' => 'secret123'];
    $mappings = ['email' => ['attribute' => 'email']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        LaravelIngest\Tests\Fixtures\Models\FillableUser::class
    );

    expect($result)
        ->toHaveKey('name')
        ->not->toHaveKey('password');
});

it('includes fillable attributes when model has explicit guarded array', function () {
    $service = new DataTransformationService();

    $processedData = ['email' => 'test@example.com', 'name' => 'Test', 'extra_field' => 'value'];
    $mappings = [];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    // User has guarded = [] (all fillable), but the final return true at line 100
    // is reached when the model has guarded (not empty array and not ['*'])
    // We need a model with specific guarded fields

    // Create a temporary model class inline just for this test
    $model = new class() extends Illuminate\Database\Eloquent\Model
    {
        protected $guarded = ['id']; // Specific guarded, not empty and not ['*']
        protected $table = 'users';
    };

    $result = $service->processUnmappedData(
        $processedData,
        $mappings,
        $relations,
        $manyRelations,
        $usedTopLevelKeys,
        get_class($model)
    );

    expect($result)
        ->toHaveKey('email')
        ->toHaveKey('name');
});
