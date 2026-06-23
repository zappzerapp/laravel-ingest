<?php

declare(strict_types=1);

namespace LaravelIngest\Flow;

use Flow\ETL\DataFrame;
use Flow\ETL\Exception\RuntimeException;
use LaravelIngest\Contracts\FlowEngineInterface;
use LaravelIngest\Flow\Loaders\EloquentLoader;
use LaravelIngest\Flow\Transformers\CallbackTransformer;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use Throwable;

use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\from_array;

class FlowEngine implements FlowEngineInterface
{
    public function build(IngestConfig $config, array $chunk, ?IngestRun $ingestRun = null, bool $isDryRun = false): DataFrame
    {
        $dataFrame = data_frame()->read(from_array($chunk));

        if ($config->beforeRowCallback !== null) {
            $dataFrame = $dataFrame->transform(new CallbackTransformer($config->beforeRowCallback));
        }

        if ($ingestRun !== null) {
            $loader = new EloquentLoader($config, $ingestRun, $isDryRun);
            $dataFrame = $dataFrame->write($loader);
        }

        return $dataFrame;
    }

    public function execute(DataFrame $pipeline): void
    {
        try {
            $pipeline->run();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Flow ETL pipeline execution failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
