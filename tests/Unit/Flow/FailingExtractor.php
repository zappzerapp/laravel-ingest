<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow;

use Flow\ETL\FlowContext;
use Flow\ETL\Rows;
use Generator;
use RuntimeException;

class FailingExtractor implements \Flow\ETL\Extractor
{
    private RuntimeException $exception;

    public function __construct(RuntimeException $exception)
    {
        $this->exception = $exception;
    }

    public function extract(FlowContext $context): Generator
    {
        throw $this->exception;
        yield new Rows(); // @phpstan-ignore-line (unreachable)
    }
}
