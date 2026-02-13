<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Flow\ETL\DataFrame;
use LaravelIngest\IngestConfig;

/**
 * Interface for the Flow-ETL based engine.
 *
 * This interface abstracts Flow-ETL's Builder pattern (Flow→read()->withEntry()->write()->run())
 * into two primary operations: building a DataFrame pipeline and executing it.
 */
interface FlowEngineInterface
{
    /**
     * Build a DataFrame pipeline from the given configuration and data chunk.
     *
     * This method creates a Flow-ETL DataFrame that encapsulates the entire
     * ETL pipeline: extraction, transformation, and loading configuration.
     * The returned DataFrame can be further modified or executed.
     *
     * @param  IngestConfig  $config  The ingest configuration containing mappings, relations, and validation rules
     * @param  array<int, array<string, mixed>>  $chunk  The data chunk to process (array of row arrays)
     * @param  \LaravelIngest\Models\IngestRun|null  $ingestRun  The ingest run for tracking (optional)
     * @param  bool  $isDryRun  Whether to run in dry-run mode (simulation only)
     * @return DataFrame The configured DataFrame pipeline ready for execution
     *
     * @throws \Flow\ETL\Exception\RuntimeException If the pipeline cannot be built
     */
    public function build(IngestConfig $config, array $chunk, ?\LaravelIngest\Models\IngestRun $ingestRun = null, bool $isDryRun = false): DataFrame;

    /**
     * Execute a DataFrame pipeline.
     *
     * This method runs the Flow-ETL pipeline and processes all data through
     * the configured extraction, transformation, and loading operations.
     *
     * @param  DataFrame  $pipeline  The DataFrame pipeline to execute
     *
     * @throws \Flow\ETL\Exception\RuntimeException If execution fails
     */
    public function execute(DataFrame $pipeline): void;
}
