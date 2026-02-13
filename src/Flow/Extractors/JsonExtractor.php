<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Extractors;

use Exception;
use Flow\ETL\Adapter\JSON\JSONMachine\JsonExtractor as FlowJsonExtractor;
use Flow\ETL\FlowContext;
use Flow\Filesystem\Path;
use Generator;

class JsonExtractor extends FlowExtractor
{
    private Path $path;

    public function __construct(string $filePath)
    {
        $this->path = Path::from($filePath);
    }

    public function extract(FlowContext $context): Generator
    {
        if (!file_exists($this->path->path())) {
            throw new Exception("JSON file not found: {$this->path->path()}");
        }

        $flowExtractor = new FlowJsonExtractor($this->path);
        yield from $flowExtractor->extract($context);
    }
}
