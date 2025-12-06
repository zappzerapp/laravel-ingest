<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use LaravelIngest\IngestConfig;

interface IngestDefinition
{
    public function getConfig(): IngestConfig;
}
