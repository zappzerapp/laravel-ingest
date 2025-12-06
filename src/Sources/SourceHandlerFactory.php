<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\InvalidConfigurationException;

class SourceHandlerFactory
{
    /**
     * @throws InvalidConfigurationException
     */
    public function make(SourceType $sourceType): SourceHandler
    {
        $handlers = config('ingest.handlers', []);

        if (!isset($handlers[$sourceType->value])) {
            throw new InvalidConfigurationException("Source type '{$sourceType->value}' is not supported or configured in config/ingest.php.");
        }

        $handlerClass = $handlers[$sourceType->value];

        return app($handlerClass);
    }
}
