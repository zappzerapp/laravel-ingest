<?php

declare(strict_types=1);

namespace LaravelIngest\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \LaravelIngest\Models\IngestRun start(string $importer, mixed $payload = null, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, bool $isDryRun = false)
 * @method static \LaravelIngest\Models\IngestRun retry(\LaravelIngest\Models\IngestRun $originalRun, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, bool $isDryRun = false)
 * @method static \LaravelIngest\Contracts\IngestDefinition getDefinition(string $slug)
 * @method static array getDefinitions()
 *
 * @see \LaravelIngest\IngestManager
 */
class Ingest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelIngest\IngestManager::class;
    }
}
