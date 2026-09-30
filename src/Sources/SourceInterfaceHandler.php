<?php

declare(strict_types=1);

namespace LaravelIngest\Sources;

use Generator;
use LaravelIngest\Concerns\ProcessesSource;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Contracts\SourceInterface;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;

/**
 * Adapts a SourceInterface passed to IngestConfig::fromSource() to the SourceHandler pipeline,
 * so custom sources get the same header normalization and validation as the built-in handlers.
 */
class SourceInterfaceHandler implements SourceHandler
{
    use ProcessesSource;

    public function __construct(
        protected SourceInterface $source
    ) {}

    /**
     * @throws SourceException
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator
    {
        yield from $this->processRows($this->source->read(), $config);
    }

    public function getTotalRows(): ?int
    {
        return null;
    }

    public function getProcessedFilePath(): ?string
    {
        return null;
    }

    public function cleanup(): void {}
}
