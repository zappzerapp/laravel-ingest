---
label: Troubleshooting
order: 90
---

# Troubleshooting

## 1. "File not found" bei Filesystem-Quellen

**Fehlermeldung**:
```
We could not find the file at '/path/to/file.csv' using the disk 'local'.
```

**Ursachen**:
- Der Pfad ist relativ (z.B. `imports/file.csv` statt `/absolute/path/file.csv`).
- Die Datei existiert nicht auf dem konfigurierten Disk.
- Der Disk ist nicht korrekt in `config/filesystems.php` konfiguriert.

**Lösungen**:

1. **Absolute Pfade verwenden**:
   ```php
   ->fromSource(SourceType::FILESYSTEM, ['path' => base_path('storage/app/imports/file.csv')])
   ```

2. **Existenz prüfen**:
   ```php
   use Illuminate\Support\Facades\Storage;
   Storage::disk('local')->exists('imports/file.csv'); // true/false
   ```

3. **Disk-Konfiguration prüfen**:
   ```php
   // config/filesystems.php
   'disks' => [
       'local' => [
           'driver' => 'local',
           'root' => storage_path('app'),
       ],
   ],
   ```

---

## 2. Memory-Limit Fehler

**Fehlermeldung**:
```
Allowed memory size of 134217728 bytes exhausted
```

**Ursachen**:
- Die CSV/Excel-Datei ist zu groß für das aktuelle `memory_limit` in PHP.
- Zu viele Zeilen werden gleichzeitig verarbeitet (z.B. `chunkSize` zu groß).

**Lösungen**:

1. **`memory_limit` erhöhen** (in `php.ini`):
   ```ini
   memory_limit = 512M
   ```

2. **Kleinere Chunks verwenden**:
   ```php
   ->setChunkSize(500) // Standard: 100
   ```

3. **Transaktionen deaktivieren** (für maximale Performance):
   ```php
   ->transactionMode(TransactionMode::NONE)
   ```

4. **Queue-Worker optimieren**:
   ```bash
   php artisan queue:work --memory=512
   ```

---

## 3. "Column not found" bei `strictHeaders(true)`

**Fehlermeldung**:
```
The column 'user_email' was not found in the source file headers.
```

**Ursachen**:
- Die Spaltenüberschrift im CSV stimmt nicht mit der Definition in `map()` oder `relate()` überein.
- Groß-/Kleinschreibung oder Leerzeichen unterscheiden sich (z.B. "E-Mail" vs. "email").

**Lösungen**:

1. **Aliase verwenden**:
   ```php
   ->map(['E-Mail', 'Email', 'user_email'], 'email')
   ```

2. **`strictHeaders(false)` setzen** (wenn die Spalte optional ist):
   ```php
   ->strictHeaders(false)
   ```

3. **CSV-Datei anpassen** (z.B. mit Excel oder `sed`):
   ```bash
   # Ersetze Leerzeichen in Headern (Linux/Mac)
   sed -i '1s/ /_/g' input.csv
   ```

---

## 4. "Connection timeout" bei großen Uploads

**Fehlermeldung**:
```
Connection timeout or Request timeout exceeded
```

**Ursachen**:
- Die Upload-Zeit überschreitet die PHP `max_execution_time`.
- Der Webserver hat eine geringere Timeout-Einstellung.
- Die Datei ist zu groß für den Upload.

**Lösungen**:

1. **PHP-Konfiguration anpassen**:
   ```ini
   ; php.ini
   max_execution_time = 300
   upload_max_filesize = 100M
   post_max_size = 100M
   max_input_time = 300
   ```

2. **Webserver-Timeout erhöhen** (nginx Beispiel):
   ```nginx
   client_max_body_size 100M;
   proxy_connect_timeout 300;
   proxy_send_timeout 300;
   proxy_read_timeout 300;
   ```

3. **Chunked Uploads im Frontend implementieren**:
   ```javascript
   // Für sehr große Dateien
   const chunkSize = 1024 * 1024; // 1MB chunks
   // Implementieren Sie chunk-by-chunk Upload
   ```

---

## 5. "Queue worker is not running"

**Fehlermeldung**:
```
Job failed after maximum attempts
```

**Ursachen**:
- Der Queue-Worker läuft nicht.
- Der Worker ist abgestürzt oder hat das Zeitlimit überschritten.
- Queue-Konfiguration ist fehlerhaft.

**Lösungen**:

1. **Worker starten und überwachen**:
   ```bash
   # Starten
   php artisan queue:work
   
   # Mit Supervisord überwachen
   php artisan queue:work --daemon --sleep=1 --tries=3
   ```

2. **Supervisor-Konfiguration** (`/etc/supervisor/conf.d/laravel-worker.conf`):
   ```ini
   [program:laravel-worker]
   process_name=%(program_name)s_%(process_num)02d
   command=php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --max-time=3600
   autostart=true
   autorestart=true
   user=www-data
   numprocs=4
   redirect_stderr=true
   stdout_logfile=/path/to/your/project/storage/logs/worker.log
   stopwaitsecs=3600
   ```

3. **Queue-Status prüfen**:
   ```bash
   php artisan queue:failed
   php artisan queue:retry all
   ```

---

## 6. "Foreign key constraint violation"

**Fehlermeldung**:
```
SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row
```

**Ursachen**:
- `relate()` oder `relateMany()` kann den referenzierten Datensatz nicht finden.
- Die referenzierte ID existiert nicht in der Zieltabelle.
- Die Beziehung ist falsch konfiguriert.

**Lösungen**:

1. **Debug-Modus für Beziehungen aktivieren**:
   ```php
   ->relate('category_name', 'category', Category::class, 'name', createIfMissing: true)
   ```

2. **Datenvalidierung vor dem Import**:
   ```php
   ->beforeRow(function(array &$row) {
       // Prüfen ob Kategorie existiert
       if (!empty($row['category_name'])) {
           $exists = Category::where('name', $row['category_name'])->exists();
           if (!$exists) {
               $row['category_name'] = 'Default Category'; // oder werfen Sie Exception
           }
       }
   })
   ```

3. **Fehlende Datensätze erstellen**:
   ```php
   ->relate('category_name', 'category', Category::class, 'name', createIfMissing: true)
   ```

---

## 7. "Invalid datetime format"

**Fehlermeldung**:
```
DateTime::__construct(): Failed to parse time string
```

**Ursachen**:
- Das Datumsformat im CSV entspricht nicht dem erwarteten Format.
- Leere oder ungültige Datumswerte.

**Lösungen**:

1. **Datumstransformation implementieren**:
   ```php
   ->mapAndTransform('import_date', 'created_at', function($value, $row) {
       if (empty($value)) return null;
       
       // Verschiedene Formate versuchen
       $formats = ['d.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'];
       foreach ($formats as $format) {
           $date = DateTime::createFromFormat($format, $value);
           if ($date !== false) {
               return $date->format('Y-m-d H:i:s');
           }
       }
       
       throw new \InvalidArgumentException("Invalid date format: {$value}");
   })
   ```

2. **Flexible Validierung**:
   ```php
   ->validate([
       'import_date' => 'nullable|date_format:d.m.Y|date_format:Y-m-d'
   ])
   ```

---

## 8. Performance ist sehr langsam

**Symptome**:
- Import von 10.000 Zeilen dauert mehrere Minuten.
- Hohe CPU- und Speicher-Auslastung.

**Ursachen**:
- Ineffiziente Datenbankabfragen in Hooks.
- Zu kleine Chunk-Size.
- Fehlende Indizes in der Datenbank.

**Lösungen**:

1. **Chunk-Size optimieren**:
   ```php
   ->setChunkSize(1000) // Erhöhen für bessere Performance
   ```

2. **Datenbank-Indizes prüfen**:
   ```sql
   -- Index für keyedBy Spalte
   CREATE INDEX idx_products_email ON products(email);
   
   -- Fremdschlüssel-Indizes
   CREATE INDEX idx_products_category_id ON products(category_id);
   ```

3. **Haken optimieren**:
   ```php
   // ❌ Schlecht: N+1 Queries
   ->afterRow(function($model, $row) {
       $model->category; // Lädt Kategorie für jede Zeile einzeln
   })
   
   // ✅ Gut: Eager Loading
   ->afterRow(function($model, $row) {
       $model->load('category');
   })
   ```

4. **Transaktionen optimieren**:
   ```php
   ->transactionMode(TransactionMode::CHUNK)
   ->setChunkSize(500) // Größere Chunks mit Transaktionen
   ```

---

## 9. Debugging-Techniken

### Log-Dateien überprüfen

1. **Laravel Logs**:
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Queue Worker Logs**:
   ```bash
   tail -f storage/logs/worker.log
   ```

3. **Datenbank-Queries loggen**:
   ```php
   // In AppServiceProvider::boot()
   if (app()->environment('local')) {
       DB::listen(function($query) {
           Log::info($query->sql, $query->bindings);
       });
   }
   ```

### Test-Import mit kleinen Datenmengen

1. **Test-CSV erstellen**:
   ```csv
   name,email,price
   Test Product,test@example.com,19.99
   Another Product,another@example.com,29.99
   ```

2. **Dry-Run durchführen**:
   ```bash
   php artisan ingest:run product-importer --file=test.csv --dry-run
   ```

### Schritt-für-Schritt-Debugging

```php
->beforeRow(function(array &$row) {
    Log::debug('Processing row', ['row' => $row]);
})
->map('name', 'name')
->map('price', 'price')
->afterRow(function($model, $row) {
    Log::debug('Row processed', ['model_id' => $model->id]);
})
```

---

## 10. Häufige Konfigurationsfehler

### Falsche Source-Type-Konfiguration

```php
// ❌ Falsch
->fromSource(SourceType::UPLOAD, ['path' => 'file.csv'])

// ✅ Richtig
->fromSource(SourceType::FILESYSTEM, ['path' => 'file.csv'])
->fromSource(SourceType::UPLOAD) // Keine Parameter für Upload
```

### Fehlende Berechtigungen

```php
// In Ihrer Policy
public function import(User $user)
{
    return $user->hasPermissionTo('import-products');
}
```

### Queue-Konfiguration

```php
// config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90, // Wichtig für langlaufende Jobs
        'after_commit' => false,
    ],
],
```

---

## Nächste Schritte bei Problemen

1. **Logs prüfen**: Überprüfen Sie Laravel- und Worker-Logs
2. **Konfiguration validieren**: Stellen Sie sicher, dass alle Pfade und Berechtigungen korrekt sind
3. **Mit kleinen Datenmengen testen**: Isolieren Sie das Problem
4. **Datenbank-Performance prüfen**: Indizes und Query-Optimierung
5. **Community-Support**: Eröffnen Sie ein Issue auf GitHub mit detaillierten Informationen

Für weitere Hilfe besuchen Sie die [Dokumentation](../index.md) oder erstellen Sie ein Issue im [GitHub Repository](https://github.com/zappzerapp/laravel-ingest).