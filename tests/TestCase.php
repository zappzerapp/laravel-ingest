<?php

declare(strict_types=1);

namespace LaravelIngest\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\IngestConfig;
use LaravelIngest\IngestServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('queue.default', 'sync');
        config()->set('queue.batching.database', 'testing');
        config()->set('ingest.disk', 'local');
    }

    protected function setUpDatabase(): void
    {

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $migration = include __DIR__ . '/../database/migrations/2025_01_01_000000_create_ingest_runs_table.php';
        $migration->up();
        $migration = include __DIR__ . '/../database/migrations/2025_01_01_000001_create_ingest_rows_table.php';
        $migration->up();
        $migration = include __DIR__ . '/../database/migrations/2025_01_01_000002_add_retry_to_ingest_runs_table.php';
        $migration->up();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('stock');
            $table->string('last_modified')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products_with_category', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->timestamps();
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            IngestServiceProvider::class,
        ];
    }

    protected function createTestDefinition(IngestConfig $config): IngestDefinition
    {
        return new class($config) implements IngestDefinition
        {
            public function __construct(public IngestConfig $config) {}

            public function getConfig(): IngestConfig
            {
                return $this->config;
            }
        };
    }
}
