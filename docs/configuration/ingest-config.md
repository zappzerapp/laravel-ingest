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

### `mapAndTransform(string|array $sourceColumn, string $modelAttribute, callable $callback)`
Transforms the value before saving. Also supports column aliases.
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

// With aliases
->mapAndTransform(['status', 'Status', 'STATE'], 'is_active', fn($val) => $val === 'active')
```

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
    sourceField: 'tag_names',       // Spalte im CSV (z.B. "Laravel,PHP,API")
    relation: 'tags',                // Name der Beziehung im Model
    relatedModel: Tag::class,        // Klasse des verwandten Models
    relatedKey: 'name',              // Attribut zum Suchen/Aufösen
    separator: ','                   // Trennzeichen (Default: ",")
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

### `strictHeaders(bool $strict = true)`
Enables strict header validation. When enabled, the import will fail immediately if any mapped source column is missing from the file headers. By default, only the `keyedBy` column is validated.

```php
->strictHeaders()
->map('email', 'email')      // Must exist in source file
->map('name', 'name')        // Must exist in source file
```

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

## Composite Keys (geplant für v0.5)

Aktuell unterstützt `keyedBy()` nur einfache Schlüssel. Für zusammengesetzte Schlüssel (z.B. `['store_id', 'sku']`) können Sie Workarounds verwenden:

### Workaround 1: Künstliche Unique-Spalte erstellen
Erstellen Sie eine kombinierte Spalte in Ihrer Quelldatei:

```php
// CSV enthält: store_id, sku, product_name
// Erstellen Sie eine neue Spalte: unique_key = "store_id|sku"
->keyedBy('unique_key')
```

Beispiel CSV-Transformation:
```csv
store_id,sku,product_name,unique_key
1,PROD001,Product A,"1|PROD001"
2,PROD001,Product B,"2|PROD001"
```

### Workaround 2: Transformation in beforeRow()
Verwenden Sie die `beforeRow()` Methode, um einen kombinierten Schlüssel zur Laufzeit zu erstellen:

```php
->beforeRow(function(array &$row) {
    // Kombiniere store_id und sku zu einem einzigartigen Schlüssel
    $row['composite_key'] = ($row['store_id'] ?? '') . '|' . ($row['sku'] ?? '');
})
->keyedBy('composite_key')
```

### Workaround 3: Daten vor dem Import vorverarbeiten
Für komplexe Szenarien können Sie die Daten vor dem Import in einem separaten Prozess vorbereiten:

```php
// In einem Service oder Controller:
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
    
    // Schreibe bereinigte CSV
    $fp = fopen($outputPath, 'w');
    fputcsv($fp, array_keys($prepared[0]));
    foreach ($prepared as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
}
```

> **Hinweis**: Echte Composite-Key-Unterstützung ist für Version 0.5 geplant und wird diese Workarounds überflüssig machen.
```