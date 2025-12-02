<?php

namespace LaravelIngest\Contracts;

use LaravelIngest\IngestConfig;

interface IngestDefinition
{
    public function getConfig(): IngestConfig;
}