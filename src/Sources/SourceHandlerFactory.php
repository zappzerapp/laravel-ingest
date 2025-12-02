<?php

namespace LaravelIngest\Sources;

use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Exceptions\InvalidConfigurationException;

class SourceHandlerFactory
{
    /** @var array<string, class-string<SourceHandler>> */
    protected array $handlers = [
        SourceType::UPLOAD->value => UploadHandler::class,
        SourceType::FILESYSTEM->value => FilesystemHandler::class,
        SourceType::FTP->value => FtpHandler::class,
        SourceType::SFTP->value => FtpHandler::class,
        SourceType::URL->value => UrlHandler::class,
    ];

    /**
     * @throws InvalidConfigurationException
     */
    public function make(SourceType $sourceType): SourceHandler
    {
        if (!isset($this->handlers[$sourceType->value])) {
            throw new InvalidConfigurationException("Source type '{$sourceType->value}' is not supported.");
        }

        $handlerClass = $this->handlers[$sourceType->value];

        return app($handlerClass);
    }
}