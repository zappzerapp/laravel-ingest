<?php

declare(strict_types=1);

namespace LaravelIngest;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
            $configImporters = config('ingest.importers', []);

            foreach ($configImporters as $slug => $class) {
                if (!is_string($slug)) {
                    $slug = Str::slug(Str::kebab(class_basename($class)));
                }

                if (!isset($definitions[$slug])) {
                    $definitions[$slug] = $app->make($class);
                }
            }

            return new IngestManager($definitions, $app->make(SourceHandlerFactory::class));
        });
    }

    public function boot(): void
    {
        $this->registerGate();

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

        $this->registerRoutes();
    }

    protected function registerGate(): void
    {
        Gate::define('viewIngest', static fn($user = null) => app()->environment('local', 'testing'));
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'domain' => config('ingest.domain', null),
            'prefix' => config('ingest.path', 'ingest/api'),
            'middleware' => config('ingest.middleware', ['api', 'auth']),
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        });
    }
}
