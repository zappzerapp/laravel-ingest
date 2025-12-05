<?php

use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Sources\SourceHandlerFactory;

it('throws exception for unsupported source type', function () {
    $factory = new SourceHandlerFactory();

    $reflection = new ReflectionClass($factory);
    $property = $reflection->getProperty('handlers');
    $handlers = $property->getValue($factory);
    unset($handlers[SourceType::UPLOAD->value]);
    $property->setValue($factory, $handlers);

    $factory->make(SourceType::UPLOAD);
})->throws(InvalidConfigurationException::class, "Source type 'upload' is not supported.");