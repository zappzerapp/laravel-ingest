# Laravel Ingest

<p align="center">
    <img src="https://raw.githubusercontent.com/zappzerapp/laravel-ingest/refs/heads/main/.github/header.png" alt="Laravel Ingest Banner" width="100%">
</p>

<p align="center">
    <a href="https://packagist.org/packages/zappzerapp/laravel-ingest"><img src="https://img.shields.io/packagist/v/zappzerapp/laravel-ingest.svg?style=for-the-badge" alt="Latest Version"></a>
    <a href="https://packagist.org/packages/zappzerapp/laravel-ingest"><img src="https://img.shields.io/packagist/dt/zappzerapp/laravel-ingest.svg?style=for-the-badge" alt="Total Downloads"></a>
    <a href="https://github.com/zappzerapp/laravel-ingest/actions"><img src="https://img.shields.io/github/actions/workflow/status/zappzerapp/laravel-ingest/main-pipeline.yml?style=for-the-badge" alt="Build Status"></a>
    <a href="https://zappzerapp.github.io/laravel-ingest/"><img src="https://img.shields.io/badge/docs-online-blue.svg?style=for-the-badge" alt="Documentation"></a>
    <a href="LICENSE"><img src="https://img.shields.io/packagist/l/zappzerapp/laravel-ingest.svg?style=for-the-badge" alt="License"></a>
</p>

---

**Stop writing spaghetti code for imports.**

**Laravel Ingest** is a robust, configuration-driven ETL (Extract, Transform, Load) framework for Laravel. It replaces
fragile, procedural import scripts with elegant, declarative configuration classes.

Whether you are importing **100 rows** or **10 million**, Laravel Ingest handles the heavy lifting: streaming, chunking,
queueing, validation, relationships, and error reporting.

## ⚡ Why use this?

Most import implementations suffer from the same issues: memory leaks, timeouts, lack of validation, and messy
controllers.

Laravel Ingest solves this by treating imports as a first-class citizen:

- **♾️ Infinite Scalability:** Uses Generators and Queues to process files of *any* size with flat memory usage.
- **📝 Declarative Syntax:** Define *what* to import, not *how* to loop over it.
- **🧪 Dry Runs:** Simulate imports to find validation errors without touching the database.
- **🔗 Auto-Relations:** Automatically resolves `BelongsTo` and `BelongsToMany` relationships (e.g., finding IDs by
  names).
- **🛡️ Robust Error Handling:** Tracks every failed row and allows you to download a CSV of *only* the failures to fix
  and retry.
- **🔌 API & CLI Ready:** Comes with auto-generated API endpoints and Artisan commands.

---

## 📚 Documentation

Full documentation is available at **[zappzerapp.github.io/laravel-ingest](https://zappzerapp.github.io/laravel-ingest/)**.

---

## 🚀 Quick Start

### 1. Installation

```bash
composer require zappzerapp/laravel-ingest

# Publish config & migrations
php artisan vendor:publish --provider="LaravelIngest\IngestServiceProvider"

# Create tables
php artisan migrate
```

### 2. Define an Importer

Create a class implementing `IngestDefinition`. This is the only code you need to write.

```php
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
            ->keyedBy('email') // Identify records by email
            ->onDuplicate(DuplicateStrategy::UPDATE) // Update if exists
            
            // Map CSV columns to DB attributes
            ->map('Full Name', 'name')
            ->map(['E-Mail', 'Email Address'], 'email') // Supports aliases
            
            // Handle Relationships automatically
            ->relate('Role', 'role', Role::class, 'slug', createIfMissing: true)
            
            // Validate rows before processing
            ->validate([
                'email' => 'required|email',
                'Full Name' => 'required|string|min:3'
            ]);
    }
}
```

### 3. Register it

In `App\Providers\AppServiceProvider`:

```php
use LaravelIngest\IngestServiceProvider;

public function register(): void
{
    $this->app->tag([UserImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
}
```

### 4. Run it!

You can now trigger the import via CLI or API.

**Via Artisan (Backend / Cron):**

```bash
php artisan ingest:run user-importer --file=users.csv
```

**Via API (Frontend / Upload):**

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  -F "file=@users.csv" \
  https://your-app.com/api/v1/ingest/upload/user-importer
```

---

## 💡 Demo Project

Want to see Laravel Ingest in action? Check out our **[Laravel Ingest Demo](https://github.com/zappzerapp/Laravel-Ingest-Demo)** repository for a complete working example.

```bash
# Clone the demo
git clone https://github.com/zappzerapp/Laravel-Ingest-Demo.git
cd Laravel-Ingest-Demo

# Start and benchmark
docker compose up -d
docker compose exec app php artisan benchmark:ingest
```

---

## 🛠 Features in Depth

### Monitoring & Management

Ingest runs happen in the background. You can monitor and manage them easily:

| Command              | Description                                                           |
|:---------------------|:----------------------------------------------------------------------|
| `ingest:list`        | Show all registered importers.                                        |
| `ingest:status {id}` | Show progress bar, stats, and errors for a run.                       |
| `ingest:cancel {id}` | Stop a running import gracefully.                                     |
| `ingest:retry {id}`  | Create a **new run** containing only the rows that failed previously. |

### API Endpoints

The package automatically exposes endpoints for building UI integrations (e.g., React/Vue progress bars).

- `GET /api/v1/ingest` - List recent runs.
- `GET /api/v1/ingest/{id}` - Get status and progress.
- `GET /api/v1/ingest/{id}/errors/summary` - Get aggregated error stats (e.g., "50x Email invalid").
- `GET /api/v1/ingest/{id}/failed-rows/download` - Download a CSV of failed rows to fix & re-upload.

### Events

Hook into the lifecycle to send notifications (e.g., Slack) or trigger downstream logic.

- `LaravelIngest\Events\IngestRunStarted`
- `LaravelIngest\Events\ChunkProcessed`
- `LaravelIngest\Events\RowProcessed`
- `LaravelIngest\Events\IngestRunCompleted`
- `LaravelIngest\Events\IngestRunFailed`

### Pruning

To keep your database clean, logs are prunable. Add this to your scheduler:

```php
$schedule->command('model:prune', [
    '--model' => [LaravelIngest\Models\IngestRow::class],
])->daily();
```

---

## 🧩 Configuration Reference

The `IngestConfig` fluent API handles complex scenarios with ease.

```php
IngestConfig::for(Product::class)
    // Sources: UPLOAD, FILESYSTEM, URL, FTP, SFTP
    ->fromSource(SourceType::FTP, ['disk' => 'erp', 'path' => 'daily.csv'])
    
    // Performance
    ->setChunkSize(1000)
    ->atomic() // Wrap chunks in transactions
    
    // Logic
    ->keyedBy('sku')
    ->onDuplicate(DuplicateStrategy::UPDATE_IF_NEWER)
    ->compareTimestamp('last_modified_at', 'updated_at')
    
    // Transformation
    ->mapAndTransform('price_cents', 'price', fn($val) => $val / 100)
    ->resolveModelUsing(fn($row) => $row['type'] === 'digital' ? DigitalProduct::class : Product::class);
```

See the [Documentation](https://zappzerapp.github.io/laravel-ingest/) for all available methods.

---

## 🧪 Testing

We provide a Docker-based test environment to ensure consistency.

```bash
# Start Docker
composer docker:up

# Run Tests
composer docker:test

# Check Coverage
composer docker:coverage
```

---

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](.github/CONTRIBUTING.md) for details.

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
