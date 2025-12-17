# The IngestConfig Class

The `IngestConfig` object is the heart of every importer. It provides a fluent, expressive API to define every aspect of the import process, from the data source to validation and data transformation.

All configuration is done within the `getConfig()` method of your `IngestDefinition` class.

### Core Methods

#### `for(string $modelClass)`
Initializes the configuration for a specific Eloquent model. This is always the first call.
```php
IngestConfig::for(App\Models\Product::class)
```

#### `fromSource(SourceType $sourceType, array $options = [])`
Defines the source of the data. See the [Available Source Types](./source-types.md) page for details on each type and its required options.
```php
->fromSource(SourceType::FTP, ['disk' => 'ftp_erp', 'path' => '/exports/products.csv'])
```

### Data Identification

#### `keyedBy(string $sourceField)`
Sets the unique identifier column from your source file (e.g., SKU, email). This is crucial for handling duplicates.
```php
->keyedBy('product_sku')
```

#### `onDuplicate(DuplicateStrategy $strategy)`
Defines what to do when a record with the same key already exists in the database.
- `DuplicateStrategy::UPDATE`: Updates the existing record with the new data.
- `DuplicateStrategy::SKIP`: (Default) Ignores the incoming row and leaves the existing record unchanged.
- `DuplicateStrategy::FAIL`: Marks the incoming row as failed and reports a duplicate error.

```php
->onDuplicate(DuplicateStrategy::UPDATE)
```

### Mapping & Transformation

#### `map(string $sourceField, string $modelAttribute)`
Creates a direct mapping from a source column to a model attribute.
```php
// Maps the 'product_name' column from the CSV to the 'name' attribute on the Product model.
->map('product_name', 'name')
```

#### `mapAndTransform(string $sourceField, string $modelAttribute, callable $transformer)`
Maps a source column and transforms its value using a closure before it's assigned to the model. The closure receives the value and the full original row data as arguments.
```php
// Converts a price string like "1,99 EUR" to cents.
->mapAndTransform('price', 'price_in_cents', function($value, $row) {
    $price = str_replace([' EUR', ','], ['', '.'], $value);
    return (int) ((float) $price * 100);
})
```

#### `relate(string $sourceField, string $relationName, string $relatedModel, string $relatedKey = 'id')`
Resolves a `BelongsTo` relationship. It looks up the related model's ID and sets the foreign key on the primary model.
```php
// The source file has a 'category_name' column (e.g., "Electronics").
// This will find the Category model where 'name' is "Electronics" and set 'category_id' on the Product.
->relate('category_name', 'category', App\Models\Category::class, 'name')
```

### Validation

#### `validate(array $rules)`
Applies Laravel validation rules to the incoming data *before* transformation.
```php
->validate([
    'product_sku' => 'required|alpha_dash|max:50',
    'stock' => 'required|integer|min:0',
])
```

#### `validateWithModelRules()`
Merges rules from a static `getRules()` method on your target model. This is great for keeping validation logic centralized.
```php
// In your User.php model:
public static function getRules(): array
{
    return ['email' => 'required|email'];
}

// In your importer:
->validateWithModelRules()
```

### Process Control

#### `setChunkSize(int $size)`
Defines how many rows are processed in a single background job. The default is 100.
```php
->setChunkSize(500)
```

#### `setDisk(string $diskName)`
Specifies the Laravel Filesystem disk to use for `UPLOAD` or `FILESYSTEM` sources. Defaults to the disk set in `config/ingest.php`.
```php
->setDisk('s3_imports')
```

#### `atomic()`
Wraps the processing of each chunk in a database transaction. If any row within a chunk fails, all changes from that chunk are rolled back. This ensures data integrity.
```php
->atomic()
```

### Callbacks / Hooks

#### `beforeRow(callable $callback)`
Executes a closure just before a row is validated and processed. You can modify the data by reference.
```php
// Ensure all names are properly capitalized.
->beforeRow(function(array &$data) {
    $data['name'] = ucwords(strtolower($data['name']));
})
```

#### `afterRow(callable $callback)`
Executes a closure *after* a row has been successfully processed and persisted. The closure receives the saved model instance and the original row data.
```php
// Sync a relationship after the main model is created.
->afterRow(function(Product $product, array $originalData) {
    $tags = explode(',', $originalData['tags']);
    $product->tags()->sync($tags);
})
```