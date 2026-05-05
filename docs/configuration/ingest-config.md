---
label: The IngestConfig Class
order: 100
---

# The IngestConfig Class

The `IngestConfig` object is the declarative heart of your importer. It allows you to define the entire ETL (Extract, Transform, Load) process in a fluent, readable way.

All configuration happens inside the `getConfig()` method of your importer class.

## Basic Setup

### `for(string $modelClass)`
**Required.** Initializes the configuration for a specific Eloquent model. This must be the static entry point.

```php
IngestConfig::for(\App\Models\Product::class)
```

### `fromSource(SourceType $type, array $options = [])`
**Required.** Defines where the data comes from.
- **$type**: An enum instance of `LaravelIngest\Enums\SourceType`.
- **$options**: An associative array of options required by the specific source handler (e.g., `path`, `disk`, `url`).

```php
->fromSource(SourceType::FTP, ['disk' => 'ftp-disk', 'path' => 'import.csv'])
```

---

## Identity & Duplicates

### `keyedBy(string $sourceColumn)`
Defines the "Unique ID" column in your **source file** (not the database column name). This is used to check if a record already exists.

```php
// The CSV has a column "EAN-Code" which is unique
->keyedBy('EAN-Code')
```

### `onDuplicate(DuplicateStrategy $strategy)`
Defines behavior when a record with the `keyedBy` value is found in the database.
- `DuplicateStrategy::SKIP`: (Default) Do nothing. Keep the old record.
- `DuplicateStrategy::UPDATE`: Overwrite the database record with new data.
- `DuplicateStrategy::FAIL`: Stop processing this row and mark it as failed.
- `DuplicateStrategy::UPDATE_IF_NEWER`: Only update if the source data is newer (requires `compareTimestamp()`).

```php
->onDuplicate(DuplicateStrategy::UPDATE)
```

### `compareTimestamp(string $sourceColumn, string $dbColumn = 'updated_at')`
Used with `DuplicateStrategy::UPDATE_IF_NEWER`. Compares a timestamp from the source data with a database column to determine if the record should be updated.

```php
->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
->compareTimestamp('last_modified', 'updated_at')
```

---

## Mapping & Transformation

### `map(string|array $sourceColumn, string $modelAttribute)`
A 1:1 copy from source to database. Supports column aliases for files with varying headers.

```php
// Simple mapping
->map('First Name', 'first_name')

// With aliases - first match wins
->map(['email', 'E-Mail', 'user_email'], 'email')
->map(['name', 'full_name', 'Name'], 'name')
```

### `mapAndTransform(string|array $sourceColumn, string $modelAttribute, Closure|TransformerInterface|string|array $transformer)`
Transforms the value before saving. Also supports column aliases. The transformer can be provided in four forms:

1. **Closure**: `fn($value, array $row) => mixed`
2. **TransformerInterface instance**: `new NumericTransformer(decimals: 2)`
3. **Class-name string** (auto-resolved): `DivideByHundredTransformer::class`
4. **Array of transformers** (applied in sequence): `[new NumericTransformer(), fn($val) => $val * 100]`

```php
// Closure
->mapAndTransform('Last Name', 'full_name', function($value, $row) {
    return $row['First Name'] . ' ' . $value;
})

// TransformerInterface instance
->mapAndTransform('price_cents', 'price', new NumericTransformer(decimals: 2))

// Class-name string (auto-resolved)
->mapAndTransform('price_cents', 'price', DivideByHundredTransformer::class)

// Array of transformers (applied in sequence)
->mapAndTransform('price_cents', 'price', [
    fn($val) => (int) $val,
    new DivideByHundredTransformer(),
])

// With aliases
->mapAndTransform(['status', 'Status', 'STATE'], 'is_active', fn($val) => $val === 'active')
```

**See also:** [Transformation Pipelines](docs/advanced/advanced-features.md#transformation-pipelines)

### `mapAndValidate(string|array $sourceColumn, string $modelAttribute, ValidatorInterface|string|array $validator)`
Maps a column and validates it using a custom validator before saving. Supports column aliases. The validator can be provided as a `ValidatorInterface` instance, a class-name string (auto-resolved), or an array of validators.

```php
// ValidatorInterface instance
->mapAndValidate('email', 'email', new EmailValidator())

// Class-name string (auto-resolved)
->mapAndValidate('email', 'email', EmailValidator::class)

// Array of validators
->mapAndValidate('price', 'price', [MinValueValidator::class, NumericValidator::class])
```

**See also:** [Validators](docs/advanced/advanced-features.md#validators)

### `mapTransformAndValidate(string|array $sourceColumn, string $modelAttribute, array $transformers, array $validators)`
Combines transformation and validation in a single call. Applies all transformers in sequence, then runs all validators. Supports column aliases.

```php
->mapTransformAndValidate(
    'price',
    'price',
    [fn($val) => (float) $val, new DivideByHundredTransformer()],
    [MinValueValidator::class, NumericValidator::class]
)
```

**See also:** [Validators](docs/advanced/advanced-features.md#validators) and [Transformation Pipelines](docs/advanced/advanced-features.md#transformation-pipelines)

### `mapWhen(string|array $sourceColumn, string $modelAttribute, Closure|ConditionalMappingInterface $condition, Closure|TransformerInterface|string|null $transformer = null, Closure|ValidatorInterface|string|null $validator = null)`
Conditionally applies a mapping only when the given condition evaluates to `true` for the current row. Supports column aliases, optional transformation, and optional validation.

The condition can be a `Closure` receiving the full row array, or a class implementing `ConditionalMappingInterface`.

```php
// Conditional mapping with a closure
->mapWhen('status', 'is_active', fn($row) => $row['type'] === 'user', fn($val) => $val === 'active')

// Conditional mapping with a transformer and validator
->mapWhen(
    'price',
    'price',
    fn($row) => $row['type'] === 'premium',
    new NumericTransformer(decimals: 2),
    MinValueValidator::class
)

// Using a ConditionalMappingInterface class
->mapWhen('status', 'order_status', new OrderStatusMapping())
```

**See also:** [Conditional Mappings](docs/advanced/advanced-features.md#conditional-mappings)

### `nest(string $sourceColumn, Closure $callback)`
Maps nested data structures (e.g., JSON objects) into related models. The callback receives a `NestedIngestConfig` instance to define mappings for the nested fields.

```php
->nest('address', function(NestedIngestConfig $config) {
    $config
        ->map('street', 'street_address')
        ->map('city', 'city')
        ->map('zip', 'postal_code')
        ->keyedBy('zip');
})
```

**See also:** [Nested Mappings](docs/advanced/advanced-features.md#nested-mappings)

### `relate(string $sourceColumn, string $relationName, string $relatedModel, string $relatedKey, bool $createIfMissing = false)`
Automatically resolves `BelongsTo` relationships.
1. Takes the value from `$sourceColumn`.
2. Searches `$relatedModel` where `$relatedKey` matches that value.
3. If found, assigns the ID to the foreign key of `$relationName`.
4. If `createIfMissing` is `true` and no match is found, creates the related record automatically.

```php
// Source: "Category: Smartphones"
// Database lookup: Category::where('name', 'Smartphones')->first()
// Result: $product->category_id = $foundCategory->id
->relate('Category', 'category', \App\Models\Category::class, 'name')

// Auto-create missing categories
->relate('Category', 'category', \App\Models\Category::class, 'name', createIfMissing: true)
```

### `relateMany(string $sourceField, string $relationName, string $relatedModel, string $relatedKey = 'id', string $separator = ',')`
Synchronizes Many-to-Many relationships from a delimited list in your source data. Perfect for tags, categories, or any pivot table relationship.

**Parameters:**
- **$sourceField**: Column name in your source file containing the delimited values
- **$relationName**: Name of the BelongsToMany relationship in your model
- **$relatedModel**: The related Eloquent model class
- **$relatedKey**: Attribute to search for in the related model (default: 'id')
- **$separator**: Character used to split values (default: ',')

```php
// Example 1: Tags from CSV column "Tags" containing "PHP, Laravel, Backend"
->relateMany(
    sourceField: 'tag_names',       // Column in CSV (e.g. "Laravel,PHP,API")
    relation: 'tags',                // Name of the relationship in the model
    relatedModel: Tag::class,        // Class of the related model
    relatedKey: 'name',              // Attribute to search/resolve by
    separator: ','                   // Separator (Default: ",")
)

// Example 2: Categories with semicolon separator
->relateMany(
    sourceField: 'categories',
    relation: 'categories',
    relatedModel: Category::class,
    relatedKey: 'slug',
    separator: ';'
)

// Example 3: Multiple role assignments
->relateMany(
    sourceField: 'user_roles',
    relation: 'roles',
    relatedModel: Role::class,
    relatedKey: 'name',
    separator: '|'
)
```

**Behavior:**
- Duplicates in the source list are automatically handled
- Non-existing related records will cause the row to fail (unless you handle them in `beforeRow()`)
- The entire pivot table for the relationship is synced (existing relationships not in the list will be removed)
```

---

## Validation

### `validate(array $rules)`
Applies Laravel validation rules to the incoming data *before* it is transformed or saved. Keys must match the **source file columns**.

```php
->validate([
    'EAN-Code' => 'required|numeric|digits:13',
    'Price' => 'required|numeric|min:0',
])
```

### `validateWithModelRules()`
Merges validation rules defined in the target model's static `getRules()` method. Useful for DRY (Don't Repeat Yourself). Rules from `validate()` take precedence over model rules.

```php
// In Product.php
public static function getRules(): array
{
    return [
        'sku' => 'required|string',
        'name' => 'required|min:3',
    ];
}

// In Config
->validateWithModelRules()
->validate(['price' => 'required|numeric']) // Additional rules
```

### `expectSchema(array $schema)`
Validates the structure of the source data before processing. Define expected columns with their types and constraints. If the source schema does not match, the import fails early with a clear error.

```php
->expectSchema([
    'sku' => ['type' => 'string', 'required' => true],
    'price' => ['type' => 'numeric', 'required' => true, 'nullable' => false],
    'description' => ['type' => 'string', 'nullable' => true],
])
```

**See also:** [Schema Validation](docs/advanced/advanced-features.md#schema-validation)

---

## Hooks

### `beforeRow(callable $callback)`
Executed **before** validation. Allows you to modify the raw data array by reference. Perfect for cleaning up messy data globally.

> **Note:** Closures passed to `beforeRow` are automatically wrapped in a `SerializableClosure` for serialization safety. This ensures the config can be cached or queued without losing the callback logic.

```php
->beforeRow(function(array &$data) {
    // Remove invisible characters from all keys
    $data = array_combine(
        array_map('trim', array_keys($data)), 
        $data
    );
})
```

### `afterRow(callable $callback)`
Executed **after** the model has been successfully saved.
- **$model**: The saved Eloquent model.
- **$row**: The original raw data.

> **Note:** Closures passed to `afterRow` are automatically wrapped in a `SerializableClosure` for serialization safety. This ensures the config can be cached or queued without losing the callback logic.

```php
->afterRow(function(Product $product, array $row) {
    // Sync tags or trigger side effects
    $product->search_index_updated_at = now();
    $product->saveQuietly();
})
```

**See also:** [Import Events](docs/advanced/advanced-features.md#import-events)

---

## Processing Options

### `setChunkSize(int $size)`
Determines how many rows are processed per background job. Default: `100`.
- Increase for simple inserts to reduce queue overhead.
- Decrease for memory-heavy operations (e.g., image processing in `afterRow`).

### `atomic()`
Wraps each chunk in a Database Transaction. If **one** row in the chunk fails, **all** rows in that chunk are rolled back.
- **Default**: Disabled (Rows are committed individually).

### `setDisk(string $disk)`
Overrides the default filesystem disk (from `config/ingest.php`) for this specific importer.
```php
->setDisk('s3_private_bucket')
```

### `strictHeaders(bool $strict = true)`
Enables strict header validation. When enabled, the import will fail immediately if any mapped source column is missing from the file headers. By default, only the `keyedBy` column is validated.

```php
->strictHeaders()
->map('email', 'email')      // Must exist in source file
->map('name', 'name')        // Must exist in source file
```

---

## Tracing & Debugging

### `withTracing()`
Enables full tracing for the import. This records detailed logs for both mappings and transformations, making it easier to debug complex imports.

```php
->withTracing()
```

### `traceTransformations()`
Enables tracing for transformations only. Records how each value is transformed during the import process.

```php
->traceTransformations()
```

### `traceMappings()`
Enables tracing for mappings only. Records how source columns are mapped to model attributes.

```php
->traceMappings()
```

**See also:** [Debugging & Tracing](docs/advanced/advanced-features.md#debugging--tracing)

---

## Event Handling

### `withEventHandler(ImportEventHandlerInterface $handler)`
Registers a custom event handler to hook into the import lifecycle. The handler must be an instance of `ImportEventHandlerInterface`.

```php
->withEventHandler(new SendSlackNotificationHandler())
```

**See also:** [Import Events](docs/advanced/advanced-features.md#import-events)

---

## Dynamic Model Resolution

### `resolveModelUsing(callable $callback)`
Allows you to dynamically determine which Eloquent model to use based on the row data. This is useful when importing heterogeneous data into different tables.

- **Callback Signature**: `fn(array $rowData): string`
- **Returns**: A fully qualified model class name.

```php
use App\Models\{User, AdminUser, Customer};

IngestConfig::for(User::class)
    ->resolveModelUsing(function(array $row) {
        return match($row['user_type'] ?? 'user') {
            'admin' => AdminUser::class,
            'customer' => Customer::class,
            default => User::class,
        };
    })
    ->map('email', 'email')
    ->map('name', 'name');
```

> **Note:** The base model class passed to `IngestConfig::for()` is used as a fallback if no resolver is set.

---

## Transaction Modes

### `transactionMode(TransactionMode $mode)`
Fine-grained control over database transaction behavior.

- `TransactionMode::NONE`: No transactions (default). Each row is committed individually.
- `TransactionMode::CHUNK`: Wraps each chunk in a transaction. Same as calling `atomic()`.
- `TransactionMode::ROW`: Wraps each individual row in its own transaction.

```php
use LaravelIngest\Enums\TransactionMode;

->transactionMode(TransactionMode::ROW)
```

---

## Reusable Mappings

When multiple importers share the same field mappings (e.g., products appear in both orders and refunds), define reusable mapping classes that implement `MappingInterface`.

### Creating a Mapping Class

Create mapping classes in your application (e.g., `app/Ingest/Mappings/`):

```php
// app/Ingest/Mappings/ProductMapping.php
use LaravelIngest\Contracts\MappingInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\Transformers\NumericTransformer;

class ProductMapping implements MappingInterface
{
    public function apply(IngestConfig $config, string $prefix = ''): IngestConfig
    {
        $prefix = $prefix !== '' ? "{$prefix}_" : '';

        return $config
            ->map("{$prefix}product_id", 'product_id')
            ->map("{$prefix}product_name", 'name')
            ->mapAndTransform(
                "{$prefix}price_cents",
                'price',
                new NumericTransformer(decimals: 2)
            )
            ->map("{$prefix}sku", 'sku');
    }
}
```

### Using Mappings in Importers

```php
class OrderImporter implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for(Order::class)
            ->fromSource(SourceType::UPLOAD)
            ->map('order_id', 'id')
            ->map('customer_email', 'customer_email')
            ->applyMapping(new ProductMapping(), 'line_item');  // Prefix: line_item_product_id
    }
}

class RefundImporter implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for(Refund::class)
            ->fromSource(SourceType::UPLOAD)
            ->map('refund_id', 'id')
            ->applyMapping(new ProductMapping());  // No prefix needed
    }
}
```

### Configurable Mappings

Add fluent configuration methods for flexibility:

```php
// app/Ingest/Mappings/ProductMapping.php
class ProductMapping implements MappingInterface
{
    private bool $includeSku = true;
    private ?int $priceDecimals = 2;

    public function apply(IngestConfig $config, string $prefix = ''): IngestConfig
    {
        $prefix = $prefix !== '' ? "{$prefix}_" : '';

        $config
            ->map("{$prefix}product_id", 'product_id')
            ->map("{$prefix}product_name", 'name')
            ->mapAndTransform(
                "{$prefix}price_cents",
                'price',
                new NumericTransformer(decimals: $this->priceDecimals)
            );

        if ($this->includeSku) {
            $config->map("{$prefix}sku", 'sku');
        }

        return $config;
    }

    public function withSku(bool $include = true): self
    {
        $this->includeSku = $include;
        return $this;
    }

    public function withPriceDecimals(int $decimals): self
    {
        $this->priceDecimals = $decimals;
        return $this;
    }
}
```

Usage with configuration:

```php
IngestConfig::for(Order::class)
    ->applyMapping(
        (new ProductMapping())->withSku(false)->withPriceDecimals(0),
        'item'
    );
```

### Benefits

- **DRY**: Define product mappings once, reuse everywhere
- **Testability**: Unit test mapping logic in isolation
- **Consistency**: Same transformation logic across all importers
- **Flexibility**: Configure behavior per importer via fluent methods

---

## Composite Keys

Currently, `keyedBy()` only accepts a single string. For composite keys (e.g., `['store_id', 'sku']`), use one of the following workarounds:

### Workaround 1: Add a synthetic unique column
Create a combined column in your source file:

```php
// CSV contains: store_id, sku, product_name
// Create a new column: unique_key = "store_id|sku"
->keyedBy('unique_key')
```

Example CSV transformation:
```csv
store_id,sku,product_name,unique_key
1,PROD001,Product A,"1|PROD001"
2,PROD001,Product B,"2|PROD001"
```

### Workaround 2: Build the key in `beforeRow()`
Use the `beforeRow()` method to create a combined key at runtime:

```php
->beforeRow(function(array &$row) {
    // Combine store_id and sku into a unique key
    $row['composite_key'] = ($row['store_id'] ?? '') . '|' . ($row['sku'] ?? '');
})
->keyedBy('composite_key')
```

### Workaround 3: Pre-process data before import
For complex scenarios, prepare the data in a separate process before importing:

```php
// In a service or controller:
public function prepareImportData(string $inputPath, string $outputPath): void
{
    $csv = array_map('str_getcsv', file($inputPath));
    $headers = array_shift($csv);
    
    $prepared = [];
    foreach ($csv as $row) {
        $row = array_combine($headers, $row);
        $row['store_sku_key'] = $row['store_id'] . '_' . $row['sku'];
        $prepared[] = $row;
    }
    
    // Write cleaned CSV
    $fp = fopen($outputPath, 'w');
    fputcsv($fp, array_keys($prepared[0]));
    foreach ($prepared as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
}
```

> **Note:** Native composite-key support for the fluent API is planned for version 0.5 and will make these workarounds obsolete.
```