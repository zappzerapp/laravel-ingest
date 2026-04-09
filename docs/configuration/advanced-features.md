# Erweiterte Features

Diese Dokumentation beschreibt die neuen erweiterten Features von Laravel Ingest, die die Developer Experience und Testbarkeit erheblich verbessern.

---

## Inhalt

1. [Validatoren](#validatoren)
2. [Transformations-Pipelines](#transformations-pipelines)
3. [Bedingte Mappings](#bedingte-mappings)
4. [Eigene Datenquellen](#eigene-datenquellen)
5. [Import Events](#import-events)
6. [Verschachtelte Mappings](#verschachtelte-mappings)
7. [Schema-Validierung](#schema-validierung)
8. [Debugging & Tracing](#debugging--tracing)

---

## Validatoren

Validatoren sind analog zu Transformern für Validierungs-Logik - wiederverwendbar, testbar und deklarativ.

### Grundprinzip

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

### Built-in Validatoren

#### RequiredValidator

Prüft, ob ein Feld einen Wert enthält.

```php
use LaravelIngest\Validators\RequiredValidator;

->mapAndValidate('name', 'product_name', RequiredValidator::class)
```

Für null, leere Strings und leere Arrays schlägt die Validierung fehl.

#### EmailValidator

Validiert E-Mail-Formate.

```php
use LaravelIngest\Validators\EmailValidator;

->mapAndValidate('email', 'customer_email', EmailValidator::class)
```

Leere Werte werden als gültig betrachtet (null, '').

#### RangeValidator

Prüft numerische Werte gegen Min/Max-Grenzen.

```php
use LaravelIngest\Validators\RangeValidator;

// Nur Minimum
->mapAndValidate('price', 'price', new RangeValidator(min: 0))

// Min und Max
->mapAndValidate('quantity', 'qty', new RangeValidator(min: 1, max: 100))

// Mit benutzerdefinierter Fehlermeldung
->mapAndValidate('discount', 'discount_percent', 
    new RangeValidator(min: 0, max: 100, message: 'Discount must be between 0% and 100%'))
```

#### RegexValidator

Validiert gegen ein Regex-Pattern.

```php
use LaravelIngest\Validators\RegexValidator;

// PLZ-Validierung (5 Ziffern)
->mapAndValidate('zip', 'postal_code', 
    new RegexValidator('/^\d{5}$/', 'Must be a 5-digit postal code'))
```

#### InArrayValidator

Prüft, ob ein Wert in einer erlaubten Liste enthalten ist.

```php
use LaravelIngest\Validators\InArrayValidator;

->mapAndValidate('status', 'status', 
    new InArrayValidator(['active', 'inactive', 'pending']))

// Mit Strict Mode (Typ-Prüfung)
->mapAndValidate('type', 'type', 
    new InArrayValidator(['1', '2', '3'], strict: true))
```

#### DateValidator

Validiert Datumsformate.

```php
use LaravelIngest\Validators\DateValidator;

// Standard: Y-m-d
->mapAndValidate('date', 'order_date', new DateValidator())

// Benutzerdefiniertes Format
->mapAndValidate('date', 'order_date', new DateValidator('d/m/Y'))
```

### Kombinierte Transformation + Validierung

```php
->mapTransformAndValidate(
    'price_cents',
    'price',
    [new NumericTransformer(decimals: 2)],
    [new RangeValidator(min: 0)]
)
```

---

## Transformations-Pipelines

Wende mehrere Transformationen nacheinander an:

```php
use LaravelIngest\Transformers\TrimTransformer;
use LaravelIngest\Transformers\SlugTransformer;
use LaravelIngest\Transformers\DefaultValueTransformer;

// Pipeline: Trim → Slug → Default
->mapAndTransform('title', 'slug', [
    new TrimTransformer(),
    new SlugTransformer(),
    new DefaultValueTransformer('untitled'),
])
```

### Weitere Built-in Transformer

#### TrimTransformer

```php
use LaravelIngest\Transformers\TrimTransformer;

->mapAndTransform('name', 'name', new TrimTransformer())

// Mit custom Zeichen
->mapAndTransform('code', 'code', new TrimTransformer('x'))
```

#### SlugTransformer

```php
use LaravelIngest\Transformers\SlugTransformer;

->mapAndTransform('title', 'slug', new SlugTransformer())

// Mit Unterstrich statt Bindestrich
->mapAndTransform('title', 'slug', new SlugTransformer('_'))
```

#### MapTransformer

Werte aus einer Lookup-Tabelle mappen:

```php
use LaravelIngest\Transformers\MapTransformer;

->mapAndTransform('status', 'status_code', new MapTransformer([
    'active' => 1,
    'inactive' => 0,
    'pending' => 2,
], default: -1))
```

#### ConcatTransformer

Mehrere Felder zusammenführen:

```php
use LaravelIngest\Transformers\ConcatTransformer;

// Aus verschiedenen Spalten einen vollständigen Namen bilden
->mapAndTransform(null, 'full_name', new ConcatTransformer(
    ['first_name', 'last_name'], 
    separator: ' '
))
```

#### DefaultValueTransformer

Leere Werte durch Default ersetzen:

```php
use LaravelIngest\Transformers\DefaultValueTransformer;

->mapAndTransform('description', 'description', new DefaultValueTransformer('No description available'))

// Mit custom "leere" Werte
->mapAndTransform('status', 'status', new DefaultValueTransformer(
    'unknown', 
    ['null', 'NULL', '']
))
```

---

## Bedingte Mappings

Wende Mappings nur an, wenn eine Bedingung erfüllt ist:

```php
use LaravelIngest\IngestConfig;
use LaravelIngest\Transformers\NumericTransformer;

// Verschiedene Status-Felder je nach Typ
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

Mit ConditionalMappingInterface für komplexe Logik:

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

// Verwendung
->mapWhen('status', 'order_status', new OrderStatusMapping())
```

---

## Eigene Datenquellen

Verwende `SourceInterface` für externe Datenquellen:

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

// Verwendung
IngestConfig::for(Product::class)
    ->fromSource(new ShopifyProductSource($shopDomain, $apiKey));
```

---

## Import Events

Hook in den Import-Lifecycle mit Event Handlern:

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
        // Per row logging (sparing verwenden!)
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

// Registrieren
IngestConfig::for(Product::class)
    ->withEventHandler(new SlackNotificationHandler())
    ->fromSource(SourceType::UPLOAD);
```

### ImportStats

Das `ImportStats`-Objekt enthält:

```php
$stats->totalRows           // Gesamtanzahl
$stats->successCount        // Erfolgreich verarbeitet
$stats->failureCount        // Fehlgeschlagene Zeilen
$stats->createdCount        // Neu erstellte Records
$stats->updatedCount        // Aktualisierte Records
$stats->skippedCount()      // Übersprungene Zeilen
$stats->successRate()       // Erfolgsrate in %
$stats->wasFullySuccessful() // True wenn keine Fehler
$stats->duration            // Dauer in Sekunden
$stats->toArray()           // Als Array für JSON
```

---

## Verschachtelte Mappings

Für komplexe, verschachtelte Datenstrukturen:

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

## Schema-Validierung

Definiere das erwartete Schema für bessere Fehlermeldungen:

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

Aktiviere detaillierte Logs für Debugging:

```php
IngestConfig::for(Product::class)
    ->withTracing()                    // Alles tracen
    // oder:
    ->traceTransformations()          // Nur Transformationen
    ->traceMappings()                  // Nur Mappings
```

Das Tracing protokolliert:
- Input/Output jeder Transformation
- Welche Felder wohin gemappt wurden
- Welche bedingten Mappings aktiv waren

Zugriff auf Traces:

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

## Zusammenfassung

| Feature | Zweck | API |
|---------|-------|-----|
| **Validatoren** | Wiederverwendbare, testbare Validierung | `mapAndValidate()` |
| **Pipelines** | Mehrere Transformationen hintereinander | Array an `mapAndTransform()` |
| **Bedingte Mappings** | Kontextabhängige Mapping-Logik | `mapWhen()` |
| **Custom Sources** | Externe Datenquellen (APIs, etc.) | `SourceInterface` |
| **Events** | Hook in den Import-Lifecycle | `ImportEventHandlerInterface` |
| **Nested Mappings** | Verschachtelte Datenstrukturen | `nest()` |
| **Schema** | Input-Validierung | `expectSchema()` |
| **Tracing** | Debugging-Informationen | `withTracing()` |

Alle Features folgen dem gleichen Muster: **Interface-basiert, testbar, deklarativ**.
