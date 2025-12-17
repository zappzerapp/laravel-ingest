# Installation

Getting started with Laravel Ingest is simple. Follow these steps to integrate the package into your project.

### 1. Require with Composer

First, add the package to your project's dependencies using Composer:

```bash
composer require zappzerapp/laravel-ingest
```

### 2. Publish Assets

Next, publish the configuration file and database migrations. This will create `config/ingest.php` and the necessary migration files in `database/migrations/`.

```bash
php artisan vendor:publish --provider="LaravelIngest\IngestServiceProvider"
```

You can choose to publish only the configuration or the migrations by using the tags `ingest-config` or `ingest-migrations`.

### 3. Run Migrations

Run the database migrations to create the `ingest_runs` and `ingest_rows` tables. These tables are essential for tracking the status and results of your imports.

```bash
php artisan migrate
```

That's it! Laravel Ingest is now installed and ready to be configured.