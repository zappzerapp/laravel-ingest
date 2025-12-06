<?php

namespace LaravelIngest;

use Illuminate\Support\ServiceProvider;
use LaravelIngest\Concerns\DiscoversIngestDefinitions;
use LaravelIngest\Console\CancelIngestCommand;
use LaravelIngest\Console\ListIngestsCommand;
use LaravelIngest\Console\RetryIngestCommand;
use LaravelIngest\Console\RunIngestCommand;
use LaravelIngest\Console\StatusIngestCommand;
use LaravelIngest\Sources\SourceHandlerFactory;

class IngestServiceProvider extends ServiceProvider
{
    use DiscoversIngestDefinitions;

    public const string INGEST_DEFINITION_TAG = 'ingest.definition';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ingest.php', 'ingest');

        $this->app->singleton(IngestManager::class, function ($app) {
            $definitions = $this->discoverDefinitions($app);
            return new IngestManager($definitions, $app->make(SourceHandlerFactory::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ingest.php' => config_path('ingest.php'),
            ], 'ingest-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'ingest-migrations');

            $this->commands([
                ListIngestsCommand::class,
                RunIngestCommand::class,
                StatusIngestCommand::class,
                CancelIngestCommand::class,
                RetryIngestCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}