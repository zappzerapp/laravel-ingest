<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Extractors;

use Flow\ETL\Extractor;
use Flow\ETL\FlowContext;
use Generator;

abstract class FlowExtractor implements Extractor
{
    /**
     * Extract data and return a Generator of Rows.
     *
     * @return Generator<\Flow\ETL\Rows>
     */
    abstract public function extract(FlowContext $context): Generator;
}
