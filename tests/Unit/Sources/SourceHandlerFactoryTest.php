<?php

use Illuminate\Support\Facades\Config;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Sources\SourceHandlerFactory;

it('throws exception for unsupported source type', function () {
    $handlers = Config::get('ingest.handlers');
    unset($handlers[SourceType::UPLOAD->value]);
    Config::set('ingest.handlers', $handlers);

    $factory = new SourceHandlerFactory();
    $factory->make(SourceType::UPLOAD);
})->throws(InvalidConfigurationException::class, "Source type 'upload' is not supported or configured in config/ingest.php.");