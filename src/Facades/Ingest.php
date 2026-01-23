<?php

declare(strict_types=1);

namespace LaravelIngest\Facades;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\IngestManager;
use LaravelIngest\Models\IngestRun;

/**
 * @method static IngestRun start(string $importer, mixed $payload = null, ?Authenticatable $user = null, bool $isDryRun = false)
 * @method static IngestRun retry(IngestRun $originalRun, ?Authenticatable $user = null, bool $isDryRun = false)
 * @method static IngestDefinition getDefinition(string $slug)
 * @method static array getDefinitions()
 *
 * @see IngestManager
 */
class Ingest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IngestManager::class;
    }
}
