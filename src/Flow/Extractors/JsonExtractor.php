<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Extractors;

use Flow\ETL\Adapter\JSON\JsonExtractor as FlowJsonExtractor;
use Generator;

class JsonExtractor extends FlowExtractor
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function extract(): Generator
    {
        $flowExtractor = new FlowJsonExtractor($this->filePath);
        yield from $flowExtractor->extract();
    }
}
