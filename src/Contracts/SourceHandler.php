<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Generator;
use LaravelIngest\IngestConfig;

interface SourceHandler
{
    /**
     * @param  mixed|null  $payload  Data from the trigger (e.g., UploadedFile)
     */
    public function read(IngestConfig $config, mixed $payload = null): Generator;

    public function getTotalRows(): ?int;

    public function getProcessedFilePath(): ?string;

    public function cleanup(): void;
}
