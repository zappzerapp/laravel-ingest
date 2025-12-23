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

```php
->onDuplicate(DuplicateStrategy::UPDATE)
```

---

## Mapping & Transformation

### `map(string $sourceColumn, string $modelAttribute)`
A 1:1 copy from source to database.
```php
->map('First Name', 'first_name')
```

### `mapAndTransform(string $sourceColumn, string $modelAttribute, callable $callback)`
Transforms the value before saving.
- **Callback Signature**: `fn($value, array $row)`
- **$value**: The value of the specific column.
- **$row**: The entire raw row array (useful for combining columns).

```php
// Combine First and Last name
->mapAndTransform('Last Name', 'full_name', function($value, $row) {
    return $row['First Name'] . ' ' . $value;
})

// Format currency
->mapAndTransform('Price', 'price_in_cents', fn($val) => (int)($val * 100))
```

### `relate(string $sourceColumn, string $relationName, string $relatedModel, string $relatedKey)`
Automatically resolves `BelongsTo` relationships.
1. Takes the value from `$sourceColumn`.
2. Searches `$relatedModel` where `$relatedKey` matches that value.
3. If found, assigns the ID to the foreign key of `$relationName`.

```php
// Source: "Category: Smartphones"
// Database lookup: Category::where('name', 'Smartphones')->first()
// Result: $product->category_id = $foundCategory->id
->relate('Category', 'category', \App\Models\Category::class, 'name')
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
Merges validation rules defined in the target model's static `getRules()` method. Useful for DRY (Don't Repeat Yourself).

```php
// In Product.php
public static function getRules() { return ['sku' => 'required']; }

// In Config
->validateWithModelRules()
```

---

## Hooks

### `beforeRow(callable $callback)`
Executed **before** validation. Allows you to modify the raw data array by reference. Perfect for cleaning up messy data globally.

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

```php
->afterRow(function(Product $product, array $row) {
    // Sync tags or trigger side effects
    $product->search_index_updated_at = now();
    $product->saveQuietly();
})
```

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