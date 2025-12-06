<?php

declare(strict_types=1);

namespace LaravelIngest\Concerns;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\IngestServiceProvider;

trait DiscoversIngestDefinitions
{
    protected function discoverDefinitions(Application $app): array
    {
        $definitions = $app->tagged(IngestServiceProvider::INGEST_DEFINITION_TAG);
        $keyedDefinitions = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof IngestDefinition) {
                $slug = Str::slug(class_basename($definition));
                $keyedDefinitions[$slug] = $definition;
            }
        }

        return $keyedDefinitions;
    }
}
