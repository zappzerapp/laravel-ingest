<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;

final class ChunkProcessingState
{
    /**
     * @param  array{relations: array<string, mixed>, many: array<string, mixed>}  $caches
     * @param  array{results: array<string, int>, rowsToLog: array<int, array<string, mixed>>}  $output
     */
    public function __construct(
        public readonly IngestRun $ingestRun,
        public readonly IngestConfig $config,
        public readonly bool $isDryRun,
        public array $caches,
        public array $output,
    ) {}
}
