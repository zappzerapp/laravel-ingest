# Advanced Features

This documentation describes the advanced features of Laravel Ingest that significantly improve the developer experience and testability.

---

## Contents

1. [Validators](#validators)
2. [Transformation Pipelines](#transformation-pipelines)
3. [Conditional Mappings](#conditional-mappings)
4. [Custom Data Sources](#custom-data-sources)
5. [Import Events](#import-events)
6. [Nested Mappings](#nested-mappings)
7. [Schema Validation](#schema-validation)
8. [Debugging & Tracing](#debugging--tracing)

---

## Validators

Validators are to validation logic what transformers are to transformation logic: reusable, testable, and declarative.

### Basic Principle

```php
use LaravelIngest\IngestConfig;
use LaravelIngest\Validators\EmailValidator;
use LaravelIngest\Validators\RequiredValidator;
use LaravelIngest\Validators\RangeValidator;

IngestConfig::for(Product::class)
    ->mapAndValidate('email', 'email', EmailValidator::class)
    ->mapAndValidate('price', 'price', [
        RequiredValidator::class,
        new RangeValidator(min: 0, max: 10000),
    ]);
```

### Built-in Validators

#### RequiredValidator

Checks whether a field contains a value.

```php
use LaravelIngest\Validators\RequiredValidator;

->mapAndValidate('name', 'product_name', RequiredValidator::class)
```

Validation fails for null, empty strings, and empty arrays.

#### EmailValidator

Validates email formats.

```php
use LaravelIngest\Validators\EmailValidator;

->mapAndValidate('email', 'customer_email', EmailValidator::class)
```

Empty values are considered valid (null, '').

#### RangeValidator

Checks numeric values against min/max boundaries.

```php
use LaravelIngest\Validators\RangeValidator;

// Minimum only
->mapAndValidate('price', 'price', new RangeValidator(min: 0))

// Min and Max
->mapAndValidate('quantity', 'qty', new RangeValidator(min: 1, max: 100))

// With custom error message
->mapAndValidate('discount', 'discount_percent', 
    new RangeValidator(min: 0, max: 100, message: 'Discount must be between 0% and 100%'))
```

#### RegexValidator

Validates against a regex pattern.

```php
use LaravelIngest\Validators\RegexValidator;

// Postal code validation (5 digits)
->mapAndValidate('zip', 'postal_code', 
    new RegexValidator('/^\d{5}$/', 'Must be a 5-digit postal code'))
```

#### InArrayValidator

Checks whether a value is contained in an allowed list.

```php
use LaravelIngest\Validators\InArrayValidator;

->mapAndValidate('status', 'status', 
    new InArrayValidator(['active', 'inactive', 'pending']))

// With strict mode (type checking)
->mapAndValidate('type', 'type', 
    new InArrayValidator(['1', '2', '3'], strict: true))
```

#### DateValidator

Validates date formats.

```php
use LaravelIngest\Validators\DateValidator;

// Default: Y-m-d
->mapAndValidate('date', 'order_date', new DateValidator())

// Custom format
->mapAndValidate('date', 'order_date', new DateValidator('d/m/Y'))
```

### Combined Transformation + Validation

```php
->mapTransformAndValidate(
    'price_cents',
    'price',
    [new NumericTransformer(decimals: 2)],
    [new RangeValidator(min: 0)]
)
```

---

## Transformation Pipelines

Apply multiple transformations in sequence:

```php
use LaravelIngest\Transformers\TrimTransformer;
use LaravelIngest\Transformers\SlugTransformer;
use LaravelIngest\Transformers\DefaultValueTransformer;

// Pipeline: Trim -> Slug -> Default
->mapAndTransform('title', 'slug', [
    new TrimTransformer(),
    new SlugTransformer(),
    new DefaultValueTransformer('untitled'),
])
```

### Additional Built-in Transformers

#### TrimTransformer

```php
use LaravelIngest\Transformers\TrimTransformer;

->mapAndTransform('name', 'name', new TrimTransformer())

// With custom characters
->mapAndTransform('code', 'code', new TrimTransformer('x'))
```

#### SlugTransformer

```php
use LaravelIngest\Transformers\SlugTransformer;

->mapAndTransform('title', 'slug', new SlugTransformer())

// With underscore instead of hyphen
->mapAndTransform('title', 'slug', new SlugTransformer('_'))
```

#### MapTransformer

Map values from a lookup table:

```php
use LaravelIngest\Transformers\MapTransformer;

->mapAndTransform('status', 'status_code', new MapTransformer([
    'active' => 1,
    'inactive' => 0,
    'pending' => 2,
], default: -1))
```

#### ConcatTransformer

Merge multiple fields:

```php
use LaravelIngest\Transformers\ConcatTransformer;

// Build a full name from different columns
->mapAndTransform(null, 'full_name', new ConcatTransformer(
    ['first_name', 'last_name'], 
    separator: ' '
))
```

#### DefaultValueTransformer

Replace empty values with a default:

```php
use LaravelIngest\Transformers\DefaultValueTransformer;

->mapAndTransform('description', 'description', new DefaultValueTransformer('No description available'))

// With custom "empty" values
->mapAndTransform('status', 'status', new DefaultValueTransformer(
    'unknown', 
    ['null', 'NULL', '']
))
```

---

## Conditional Mappings

Apply mappings only when a condition is met:

```php
use LaravelIngest\IngestConfig;
use LaravelIngest\Transformers\NumericTransformer;

// Different status fields depending on type
IngestConfig::for(Transaction::class)
    ->mapWhen('status', 'order_status', 
        fn($row) => $row['type'] === 'order',
        new MapTransformer(['pending' => 1, 'completed' => 2])
    )
    ->mapWhen('status', 'refund_status',
        fn($row) => $row['type'] === 'refund',
        new MapTransformer(['requested' => 1, 'processed' => 2])
    );
```

Use ConditionalMappingInterface for complex logic:

```php
use LaravelIngest\Contracts\ConditionalMappingInterface;

class OrderStatusMapping implements ConditionalMappingInterface
{
    public function shouldApply(array $rowContext): bool
    {
        return $rowContext['type'] === 'order';
    }

    public function getSourceField(): string
    {
        return 'status';
    }

    public function getModelAttribute(): string
    {
        return 'order_status';
    }

    public function getTransformer(): ?TransformerInterface
    {
        return new MapTransformer(['pending' => 1, 'completed' => 2]);
    }

    public function getValidator(): ?ValidatorInterface
    {
        return null;
    }
}

// Usage
->mapWhen('status', 'order_status', new OrderStatusMapping())
```

---

## Custom Data Sources

Use SourceInterface for external data sources:

```php
use LaravelIngest\Contracts\SourceInterface;
use Generator;

class ShopifyProductSource implements SourceInterface
{
    public function __construct(
        private string $shopDomain,
        private string $apiKey
    ) {}

    public function read(): Generator
    {
        $client = new ShopifyClient($this->shopDomain, $this->apiKey);
        
        foreach ($client->getProducts() as $product) {
            yield [
                'id' => $product['id'],
                'title' => $product['title'],
                'price' => $product['variants'][0]['price'] ?? null,
                'sku' => $product['variants'][0]['sku'] ?? null,
            ];
        }
    }

    public function getSchema(): array
    {
        return [
            'id' => ['type' => 'integer', 'required' => true],
            'title' => ['type' => 'string', 'required' => true],
            'price' => ['type' => 'numeric', 'required' => false],
            'sku' => ['type' => 'string', 'required' => false],
        ];
    }

    public function getSourceMetadata(): array
    {
        return [
            'source_type' => 'shopify',
            'shop_domain' => $this->shopDomain,
        ];
    }
}

// Usage
IngestConfig::for(Product::class)
    ->fromSource(new ShopifyProductSource($shopDomain, $apiKey));
```

---

## Import Events

Hook into the import lifecycle with event handlers:

```php
use LaravelIngest\Contracts\ImportEventHandlerInterface;
use LaravelIngest\DTOs\RowData;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\ValueObjects\ImportStats;

class SlackNotificationHandler implements ImportEventHandlerInterface
{
    public function beforeImport(IngestRun $run): void
    {
        Log::info("Starting import {$run->id}");
    }

    public function onRowProcessed(IngestRun $run, RowData $row, object $model): void
    {
        // Per row logging (use sparingly!)
    }

    public function onError(IngestRun $run, RowData $row, \Throwable $error): void
    {
        Log::error("Import error in row {$row->rowNumber}: {$error->getMessage()}");
    }

    public function afterImport(IngestRun $run, ImportStats $stats): void
    {
        $successRate = $stats->successRate();
        
        if ($stats->wasFullySuccessful()) {
            Slack::send("Import completed successfully: {$stats->successCount} rows");
        } else {
            Slack::send("Import completed with {$stats->failureCount} errors ({$successRate}% success)");
        }
    }
}

// Register
IngestConfig::for(Product::class)
    ->withEventHandler(new SlackNotificationHandler())
    ->fromSource(SourceType::UPLOAD);
```

### ImportStats

The ImportStats object contains:

```php
$stats->totalRows           // Total count
$stats->successCount        // Successfully processed
$stats->failureCount        // Failed rows
$stats->createdCount        // Newly created records
$stats->updatedCount        // Updated records
$stats->skippedCount()      // Skipped rows
$stats->successRate()       // Success rate in %
$stats->wasFullySuccessful() // True if no errors
$stats->duration            // Duration in seconds
$stats->toArray()           // As array for JSON
```

---

## Nested Mappings

For complex, nested data structures:

```php
use LaravelIngest\IngestConfig;
use LaravelIngest\NestedIngestConfig;
use LaravelIngest\Transformers\NumericTransformer;

IngestConfig::for(Order::class)
    ->map('order_id', 'id')
    ->map('customer_email', 'email')
    ->nest('line_items', function (NestedIngestConfig $nested) {
        $nested->map('sku', 'product_sku')
               ->map('name', 'product_name')
               ->mapAndTransform('qty', 'quantity', NumericTransformer::class)
               ->mapAndTransform('unit_price', 'price', [
                   new NumericTransformer(decimals: 2),
                   new RangeValidator(min: 0),
               ])
               ->keyedBy('sku');
    });
```

Input:
```json
{
    "order_id": "123",
    "customer_email": "test@example.com",
    "line_items": [
        {"sku": "ABC-001", "name": "Widget", "qty": "2", "unit_price": "19.99"},
        {"sku": "DEF-002", "name": "Gadget", "qty": "1", "unit_price": "29.99"}
    ]
}
```

---

## Schema Validation

Define the expected schema for better error messages:

```php
IngestConfig::for(Product::class)
    ->expectSchema([
        'id' => ['type' => 'integer', 'required' => true],
        'name' => ['type' => 'string', 'required' => true],
        'price' => ['type' => 'numeric', 'required' => true],
        'description' => ['type' => 'string', 'required' => false, 'nullable' => true],
    ])
    ->fromSource(SourceType::UPLOAD);
```

---

## Debugging & Tracing

Enable detailed logs for debugging:

```php
IngestConfig::for(Product::class)
    ->withTracing()                    // Trace everything
    // or:
    ->traceTransformations()          // Only transformations
    ->traceMappings()                  // Only mappings
```

Tracing logs:
- Input/output of each transformation
- Which fields were mapped where
- Which conditional mappings were active

Access traces:

```php
$service = app(DataTransformationService::class);
$traces = $service->getTraceLog();

// [
//     'price' => [
//         ['step' => 'input', 'value' => '123.456'],
//         ['step' => 'NumericTransformer', 'value' => 123.46],
//         ['step' => 'DefaultValueTransformer', 'value' => 123.46],
//     ]
// ]
```

---

## Summary

| Feature | Purpose | API |
|---------|---------|-----|
| **Validators** | Reusable, testable validation | `mapAndValidate()` |
| **Pipelines** | Multiple transformations in sequence | Array to `mapAndTransform()` |
| **Conditional Mappings** | Context-dependent mapping logic | `mapWhen()` |
| **Custom Sources** | External data sources (APIs, etc.) | `SourceInterface` |
| **Events** | Hook into the import lifecycle | `ImportEventHandlerInterface` |
| **Nested Mappings** | Nested data structures | `nest()` |
| **Schema** | Input validation | `expectSchema()` |
| **Tracing** | Debugging information | `withTracing()` |

All features follow the same pattern: **interface-based, testable, declarative**.
