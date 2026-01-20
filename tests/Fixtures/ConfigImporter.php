<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures;

use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\User;

class ConfigImporter implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for(User::class);
    }
}
