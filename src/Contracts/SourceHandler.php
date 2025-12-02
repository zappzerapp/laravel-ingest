<?php

namespace LaravelIngest\Contracts;

use Generator;
use LaravelIngest\IngestConfig;

interface SourceHandler
{
    /**
     * @param IngestConfig $config
     * @param mixed|null $payload Data from the trigger (e.g., UploadedFile)
     * @return Generator
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator;

    public function getTotalRows(): ?int;

    public function getProcessedFilePath(): ?string;

    public function cleanup(): void;
}