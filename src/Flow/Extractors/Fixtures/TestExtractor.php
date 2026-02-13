<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Extractors\Fixtures;

use Flow\ETL\FlowContext;
use Flow\ETL\Row;
use Flow\ETL\Row\Entry\IntegerEntry;
use Flow\ETL\Row\Entry\StringEntry;
use Flow\ETL\Rows;
use Generator;
use LaravelIngest\Flow\Extractors\FlowExtractor;

class TestExtractor extends FlowExtractor
{
    public function extract(FlowContext $context): Generator
    {
        yield new Rows(
            Row::create(
                new IntegerEntry('id', 1),
                new StringEntry('name', 'Test')
            )
        );
    }
}
