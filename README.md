# Laravel Ingest

> **Tagline:** Das definitive, konfigurationsgesteuerte Daten-Import-Framework für Laravel. Robust, skalierbar und
> bereit für jede Datenquelle.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zappzerapp/laravel-ingest.svg?style=flat-square)](https://packagist.org/packages/zappzerapp/laravel-ingest)
[![Total Downloads](https://img.shields.io/packagist/dt/zappzerapp/laravel-ingest.svg?style=flat-square)](https://packagist.org/packages/zappzerapp/laravel-ingest)

Laravel Ingest revolutioniert die Art und Weise, wie Laravel-Anwendungen Daten importieren. Wir beenden das Chaos von
maßgeschneiderten, fehleranfälligen Import-Skripten und bieten ein elegantes, deklaratives und robustes Framework, mit
dem Sie komplexe Daten-Import-Prozesse definieren können.

Das System kümmert sich um die "schmutzige" Arbeit – Dateiverarbeitung, Streaming, Validierung, Hintergrund-Jobs,
Fehler-Reporting und die Bereitstellung einer API – damit Sie sich auf die Geschäftslogik konzentrieren können.

## Das Kernproblem, das wir lösen

Der Import von Daten (CSV, Excel, etc.) ist oft ein schmerzhafter Prozess: repetitiver Code, fehlende Robustheit bei
großen Dateien, schlechte User Experience und mangelhaftes Error-Handling. Laravel Ingest löst dies durch einen *
*deklarativen, konfigurationsgesteuerten Ansatz**.

## Hauptmerkmale

- **Grenzenlose Skalierbarkeit**: Durch die konsequente Nutzung von **Streams und Queues** gibt es keine Obergrenze für
  die Dateigröße. Ob 100 Zeilen oder 10 Millionen, der Speicherverbrauch bleibt konstant niedrig.
- **Fluent & Expressive API**: Definieren Sie Imports lesbar und selbsterklärend mit der `IngestConfig`-Klasse.
- **Quell-agnostisch**: Importieren Sie von Datei-Uploads, (S)FTP-Servern, URLs oder jedem Laravel Filesystem Disk (
  `s3`, `local`). Leicht erweiterbar für weitere Quellen.
- **Robuste Hintergrundverarbeitung**: Nutzt standardmäßig die Laravel Queue für maximale Zuverlässigkeit.
- **Umfassendes Mapping & Validierung**: Transformieren Sie Daten on-the-fly, lösen Sie Relationen auf und verwenden Sie
  die Validierungsregeln Ihrer Eloquent-Modelle.
- **Auto-generierte API & CLI**: Steuern und überwachen Sie Importe über RESTful-Endpunkte oder die mitgelieferten
  Artisan-Befehle.
- **"Dry Runs"**: Simulieren Sie einen Import, um Validierungsfehler zu sehen, ohne einen einzigen Datenbankeintrag zu
  schreiben.

## Installation

```bash
composer require zappzerapp/laravel-ingest
```

Veröffentlichen Sie die Konfiguration und die Migrationen:

```bash
php artisan vendor:publish --provider="LaravelIngest\IngestServiceProvider"
```

Führen Sie die Migrationen aus, um die Tabellen `ingest_runs` und `ingest_rows` zu erstellen:

```bash
php artisan migrate
```

## "Hello World": Ihr erster Importer

### 1. Importer-Klasse definieren

Erstellen Sie eine Klasse, die das `IngestDefinition`-Interface implementiert. Hier definieren Sie den gesamten Prozess.

```php
// app/Ingest/UserImporter.php
namespace App\Ingest;

use App\Models\User;
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\IngestConfig;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Enums\DuplicateStrategy;

class UserImporter implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for(User::class)
            ->fromSource(SourceType::UPLOAD)
            ->keyedBy('email')
            ->onDuplicate(DuplicateStrategy::UPDATE)
            ->map('full_name', 'name')
            ->map('user_email', 'email')
            ->mapAndTransform('is_admin', 'is_admin', fn($value) => $value === 'yes')
            ->validateWithModelRules();
    }
}
```

### 2. Modell taggen

Damit das Framework Ihren Importer findet, taggen Sie ihn im `register`-Teil Ihres `AppServiceProvider`:

```php
// app/Providers/AppServiceProvider.php
use App\Ingest\UserImporter;
use LaravelIngest\IngestServiceProvider;

public function register(): void
{
    $this->app->tag([UserImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
}
```

### 3. Import ausführen

**Via API:** Senden Sie eine `multipart/form-data`-Anfrage mit einer `file`-Payload an den automatisch generierten
Endpunkt. Der Slug wird vom Klassennamen abgeleitet (`UserImporter` -> `user-importer`).

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/users.csv" \
  https://myapp.com/api/v1/ingest/upload/user-importer
```

**Via CLI:**

```bash
php artisan ingest:run user-importer --file=path/to/users.csv
```

Der Import wird nun im Hintergrund verarbeitet. Sie können den Status über die API abfragen:
`GET /api/v1/ingest/{run-id}`.

## Konfigurationsreferenz (`IngestConfig`)

Alle Konfigurationen werden über die Fluent-API in Ihrer `getConfig()`-Methode vorgenommen.

| Methode                                     | Beschreibung                                                                                                 |
|---------------------------------------------|--------------------------------------------------------------------------------------------------------------|
| `fromSource(SourceType, array)`             | Definiert die Datenquelle (z.B. `UPLOAD`, `FTP`, `URL`, `FILESYSTEM`).                                       |
| `keyedBy(string)`                           | Legt das eindeutige Feld in den Quelldaten fest (z.B. `sku`, `email`).                                       |
| `onDuplicate(DuplicateStrategy)`            | Definiert das Verhalten bei Duplikaten (`UPDATE`, `SKIP`, `FAIL`).                                           |
| `map(string, string)`                       | Mappt eine Quellspalte direkt auf ein Modell-Attribut.                                                       |
| `mapAndTransform(string, string, callable)` | Mappt und transformiert den Wert vor dem Speichern.                                                          |
| `relate(string, string, string, string)`    | Löst eine `BelongsTo`-Relation auf. Mappt `sourceField` zu `relationName` über `relatedModel`::`relatedKey`. |
| `validate(array)`                           | Definiert import-spezifische Validierungsregeln.                                                             |
| `validateWithModelRules()`                  | Nutzt die `$rules`-Eigenschaft des Ziel-Modells für die Validierung.                                         |
| `setChunkSize(int)`                         | Definiert die Anzahl der Zeilen pro Hintergrund-Job (Standard: 100).                                         |
| `setDisk(string)`                           | Definiert den Filesystem-Disk für `UPLOAD`- oder `FILESYSTEM`-Quellen.                                       |

## Fortgeschrittene Szenarien

### Nächtlicher FTP-Import

```php
// app/Ingest/DailyStockImporter.php
return IngestConfig::for(ProductStock::class)
    ->fromSource(SourceType::FTP, [
        'host' => config('services.erp.host'),
        'username' => config('services.erp.username'),
        'password' => config('services.erp.password'),
        'path' => '/stock/daily_update.csv',
    ])
    ->keyedBy('product_sku')
    ->onDuplicate(DuplicateStrategy::UPDATE)
    ->map('SKU', 'product_sku')
    ->map('Quantity', 'quantity');
```

Richten Sie einen Scheduled Command ein, der den Import auslöst:

```php
// app/Console/Kernel.php
$schedule->command('ingest:run daily-stock-importer')->dailyAt('03:00');
```

## Testing

```bash
composer test
```

---

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Credits

- [Robin Kopp](https://github.com/zappzerapp)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.