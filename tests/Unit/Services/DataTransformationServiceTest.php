<?php

declare(strict_types=1);

use Laravel\SerializableClosure\SerializableClosure;
use LaravelIngest\Contracts\ValidationResult;
use LaravelIngest\Contracts\ValidatorInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\NestedIngestConfig;
use LaravelIngest\Services\DataTransformationService;
use LaravelIngest\Tests\Fixtures\Models\FillableUser;
use LaravelIngest\Tests\Fixtures\Models\Product;

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

it('processes validators and returns errors', function () {
    $service = new DataTransformationService();

    $validator = new class() implements ValidatorInterface
    {
        public function validate(mixed $value, array $rowContext): ValidationResult
        {
            return $value > 0
                ? ValidationResult::pass()
                : ValidationResult::fail('Value must be positive');
        }
    };

    $validators = [
        'price' => [
            'attribute' => 'product_price',
            'validators' => [$validator],
        ],
    ];

    $errors = $service->processValidators(['price' => -5], $validators, IngestConfig::for(Product::class));

    expect($errors)->toHaveKey('product_price')
        ->and($errors['product_price'])->toContain('Value must be positive');
});

it('processes validators with no errors', function () {
    $service = new DataTransformationService();

    $validator = new class() implements ValidatorInterface
    {
        public function validate(mixed $value, array $rowContext): ValidationResult
        {
            return ValidationResult::pass();
        }
    };

    $validators = [
        'price' => [
            'attribute' => 'product_price',
            'validators' => [$validator],
        ],
    ];

    $errors = $service->processValidators(['price' => 100], $validators, IngestConfig::for(Product::class));

    expect($errors)->toBeEmpty();
});

it('processes conditional mappings when condition is met', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $conditionalMappings = [
        [
            'sourceField' => 'status',
            'attribute' => 'product_status',
            'condition' => fn($row) => $row['type'] === 'product',
            'transformer' => null,
            'validator' => null,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['type' => 'product', 'status' => 'active'],
        $conditionalMappings,
        $config
    );

    expect($result)->toHaveKey('product_status')
        ->and($result['product_status'])->toBe('active');
});

it('skips conditional mappings when condition is not met', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $conditionalMappings = [
        [
            'sourceField' => 'status',
            'attribute' => 'product_status',
            'condition' => fn($row) => $row['type'] === 'product',
            'transformer' => null,
            'validator' => null,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['type' => 'order', 'status' => 'pending'],
        $conditionalMappings,
        $config
    );

    expect($result)->not->toHaveKey('product_status');
});

it('processes conditional mappings with transformer', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $conditionalMappings = [
        [
            'sourceField' => 'price',
            'attribute' => 'price_cents',
            'condition' => fn($row) => true,
            'transformer' => new SerializableClosure(fn($v) => $v * 100),
            'validator' => null,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['price' => 10],
        $conditionalMappings,
        $config
    );

    expect($result['price_cents'])->toBe(1000);
});

it('processes nested data', function () {
    $service = new DataTransformationService();

    $nestedConfig = new NestedIngestConfig();
    $nestedConfig->map('sku', 'product_sku')
        ->mapAndTransform('qty', 'quantity', fn($v) => $v * 2);

    $processedData = [
        'line_items' => [
            ['sku' => 'ABC-123', 'qty' => '5'],
            ['sku' => 'DEF-456', 'qty' => '3'],
        ],
    ];

    $result = $service->processNestedData($processedData, ['line_items' => $nestedConfig]);

    expect($result)->toHaveKey('line_items')
        ->and($result['line_items'])->toHaveCount(2)
        ->and($result['line_items'][0]['product_sku'])->toBe('ABC-123')
        ->and($result['line_items'][0]['quantity'])->toBe(10);
});

it('returns empty array when nested field not present', function () {
    $service = new DataTransformationService();

    $nestedConfig = new NestedIngestConfig();
    $nestedConfig->map('sku', 'product_sku');

    $result = $service->processNestedData(['other' => 'data'], ['line_items' => $nestedConfig]);

    expect($result)->toBeEmpty();
});

it('skips non-array nested values', function () {
    $service = new DataTransformationService();

    $nestedConfig = new NestedIngestConfig();
    $nestedConfig->map('sku', 'product_sku');

    $result = $service->processNestedData(['line_items' => 'not_an_array'], ['line_items' => $nestedConfig]);

    expect($result)->toBeEmpty();
});

it('processes unmapped data', function () {
    $service = new DataTransformationService();

    $processedData = [
        'mapped_field' => 'value1',
        'relation_field' => 'value2',
        'stock' => 10,
    ];

    $mappings = ['mapped_field' => ['attribute' => 'field']];
    $relations = ['relation_field' => ['model' => Product::class]];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        Product::class
    );

    expect($result)->toHaveKey('stock')
        ->and($result['stock'])->toBe(10);
});

it('manages trace log', function () {
    $service = new DataTransformationService();

    $config = IngestConfig::for(Product::class)->traceTransformations();

    $mappings = [
        'price' => [
            'attribute' => 'final_price',
            'transformers' => [fn($v) => $v * 2],
            'aliases' => [],
        ],
    ];

    $service->processMappings(['price' => 10], $mappings, $config);

    $traceLog = $service->getTraceLog();
    expect($traceLog)->not->toBeEmpty();

    $service->clearTraceLog();
    expect($service->getTraceLog())->toBeEmpty();
});

it('traces transformer interface', function () {
    $service = new DataTransformationService();

    $config = IngestConfig::for(Product::class)->traceTransformations();

    $transformer = new class() implements LaravelIngest\Contracts\TransformerInterface
    {
        public function transform(mixed $value, array $rowContext): mixed
        {
            return $value * 10;
        }
    };

    $mappings = [
        'price' => [
            'attribute' => 'final_price',
            'transformers' => [$transformer],
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['price' => 10], $mappings, $config);

    expect($result['final_price'])->toBe(100);
    expect($service->getTraceLog())->not->toBeEmpty();
});

it('traces serializable closure transformer', function () {
    $service = new DataTransformationService();

    $config = IngestConfig::for(Product::class)->traceTransformations();

    $mappings = [
        'price' => [
            'attribute' => 'final_price',
            'transformers' => [new SerializableClosure(fn($v) => $v * 2)],
            'aliases' => [],
        ],
    ];

    $result = $service->processMappings(['price' => 10], $mappings, $config);

    expect($result['final_price'])->toBe(20);
    expect($service->getTraceLog())->not->toBeEmpty();
    expect($service->getTraceLog()['price'])->toHaveCount(2); // input + closure_1
});

it('skips trace when no transformers', function () {
    $service = new DataTransformationService();

    $config = IngestConfig::for(Product::class)->traceTransformations();

    $mappings = [
        'name' => [
            'attribute' => 'product_name',
            'transformers' => [],
            'aliases' => [],
        ],
    ];

    $service->processMappings(['name' => 'Test'], $mappings, $config);

    expect($service->getTraceLog())->toBeEmpty();
});

it('skips conditional mappings when source field missing', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $conditionalMappings = [
        [
            'sourceField' => 'status',
            'attribute' => 'product_status',
            'condition' => fn($row) => true,
            'transformer' => null,
            'validator' => null,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['type' => 'product'], // status field is missing
        $conditionalMappings,
        $config
    );

    expect($result)->toBeEmpty();
});

it('processes conditional mappings with transformer interface', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $transformer = new class() implements LaravelIngest\Contracts\TransformerInterface
    {
        public function transform(mixed $value, array $rowContext): mixed
        {
            return $value * 100;
        }
    };

    $conditionalMappings = [
        [
            'sourceField' => 'price',
            'attribute' => 'price_cents',
            'condition' => fn($row) => true,
            'transformer' => $transformer,
            'validator' => null,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['price' => 10],
        $conditionalMappings,
        $config
    );

    expect($result['price_cents'])->toBe(1000);
});

it('skips conditional mappings when validator fails', function () {
    $service = new DataTransformationService();
    $config = IngestConfig::for(Product::class);

    $validator = new class() implements ValidatorInterface
    {
        public function validate(mixed $value, array $rowContext): ValidationResult
        {
            return ValidationResult::fail('Invalid value');
        }
    };

    $conditionalMappings = [
        [
            'sourceField' => 'price',
            'attribute' => 'final_price',
            'condition' => fn($row) => true,
            'transformer' => null,
            'validator' => $validator,
            'aliases' => [],
        ],
    ];

    $result = $service->processConditionalMappings(
        ['price' => -10],
        $conditionalMappings,
        $config
    );

    expect($result)->not->toHaveKey('final_price');
});

it('skips keyedBy field in nested data processing', function () {
    $service = new DataTransformationService();

    $nestedConfig = new NestedIngestConfig();
    $nestedConfig->map('sku', 'product_sku')
        ->keyedBy('sku'); // This should be skipped

    $processedData = [
        'line_items' => [
            ['sku' => 'ABC-123'],
        ],
    ];

    $result = $service->processNestedData($processedData, ['line_items' => $nestedConfig]);

    expect($result['line_items'])->toHaveCount(1);
    expect($result['line_items'][0])->toHaveKey('product_sku');
});

it('processes nested data with transformer interface', function () {
    $service = new DataTransformationService();

    $nestedConfig = new NestedIngestConfig();

    $transformer = new class() implements LaravelIngest\Contracts\TransformerInterface
    {
        public function transform(mixed $value, array $rowContext): mixed
        {
            return $value * 10;
        }
    };

    $nestedConfig->mapAndTransform('qty', 'quantity', $transformer);

    $processedData = [
        'line_items' => [
            ['qty' => 5],
        ],
    ];

    $result = $service->processNestedData($processedData, ['line_items' => $nestedConfig]);

    expect($result['line_items'][0]['quantity'])->toBe(50);
});

it('filters unmapped data for non-fillable attributes', function () {
    $service = new DataTransformationService();

    $processedData = ['sku' => 'ABC123', 'name' => 'Product', 'fake_column' => 'value'];
    $mappings = ['sku' => ['attribute' => 'sku']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        Product::class
    );

    expect($result)->not->toHaveKey('fake_column');
});

it('filters unmapped data for guarded models by checking database columns', function () {
    $service = new DataTransformationService();

    $processedData = ['email' => 'test@example.com', 'nonexistent_column' => 'value'];
    $mappings = [];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        LaravelIngest\Tests\Fixtures\Models\User::class
    );

    expect($result)->not->toHaveKey('nonexistent_column')
        ->and($result)->toHaveKey('email');
});

it('includes unmapped data when model has partial guarded', function () {
    $service = new DataTransformationService();

    $processedData = ['name' => 'Test', 'email' => 'test@example.com'];
    $mappings = ['name' => ['attribute' => 'name']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        LaravelIngest\Tests\Fixtures\Models\User::class
    );

    expect($result)->toHaveKey('email');
});

it('excludes non-fillable attributes from unmapped data', function () {
    $service = new DataTransformationService();

    $processedData = ['name' => 'Product', 'category_id' => 1, 'non_existent' => 'value'];
    $mappings = ['name' => ['attribute' => 'name']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        LaravelIngest\Tests\Fixtures\Models\ProductWithCategory::class
    );

    expect($result)->not->toHaveKey('non_existent');
});

it('falls back to allowing all fields when Schema throws exception', function () {
    $service = new DataTransformationService();

    Illuminate\Support\Facades\Schema::shouldReceive('getColumnListing')
        ->andThrow(new Exception('Database error'));

    $processedData = ['email' => 'test@example.com', 'name' => 'Test'];
    $mappings = [];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        LaravelIngest\Tests\Fixtures\Models\User::class
    );

    expect($result)->toHaveKey('email')
        ->and($result)->toHaveKey('name');

    Illuminate\Support\Facades\Schema::partialMock();
});

it('returns true for fillable fields when model has specific guarded', function () {
    $service = new DataTransformationService();

    $processedData = ['name' => 'Product', 'category_id' => 1];
    $mappings = ['name' => ['attribute' => 'name']];
    $relations = [];
    $manyRelations = [];
    $usedTopLevelKeys = [];

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
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
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        FillableUser::class
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

    $model = new class() extends Illuminate\Database\Eloquent\Model
    {
        protected $guarded = ['id'];
        protected $table = 'users';
    };

    $result = $service->processUnmappedData(
        $processedData,
        array_merge($mappings, $relations, $manyRelations, $usedTopLevelKeys),
        get_class($model)
    );

    expect($result)
        ->toHaveKey('email')
        ->toHaveKey('name');
});
