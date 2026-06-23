<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Flow\ETL\DataFrame;
use LaravelIngest\IngestConfig;

interface FlowEngineInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $chunk
     *
     * @throws \Flow\ETL\Exception\RuntimeException
     */
    public function build(IngestConfig $config, array $chunk, ?\LaravelIngest\Models\IngestRun $ingestRun = null, bool $isDryRun = false): DataFrame;

    /**
     * @throws \Flow\ETL\Exception\RuntimeException
     */
    public function execute(DataFrame $pipeline): void;
}
