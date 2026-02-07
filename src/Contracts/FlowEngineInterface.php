<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Flow\ETL\DataFrame;
use Flow\ETL\Flow;
use LaravelIngest\Models\IngestRun;

interface FlowEngineInterface
{
    /**
     * Create a pipeline from an ingest definition.
     *
     * @param  IngestDefinition  $definition  The ingest definition containing configuration
     * @return Flow The Flow pipeline instance
     */
    public function createPipeline(IngestDefinition $definition): Flow;

    /**
     * Extract data from a source.
     *
     * @param  SourceHandler  $source  The source handler to extract from
     * @return DataFrame The extracted data as a DataFrame
     */
    public function extract(SourceHandler $source): DataFrame;

    /**
     * Transform a DataFrame using the provided transformers.
     *
     * @param  DataFrame  $df  The DataFrame to transform
     * @param  array  $transformers  Array of transformer callables or objects
     * @return DataFrame The transformed DataFrame
     */
    public function transform(DataFrame $df, array $transformers): DataFrame;

    /**
     * Load a DataFrame into a destination using the provided loader.
     *
     * @param  DataFrame  $df  The DataFrame to load
     * @param  mixed  $loader  The loader instance or callable
     */
    public function load(DataFrame $df, mixed $loader): void;

    /**
     * Execute a complete Flow pipeline.
     *
     * @param  Flow  $flow  The Flow pipeline to execute
     * @return IngestRun The result of the ingest run
     */
    public function execute(Flow $flow): IngestRun;
}
