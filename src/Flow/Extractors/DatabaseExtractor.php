<?php

declare(strict_types=1);

namespace LaravelIngest\Flow\Extractors;

use Flow\ETL\Extractor\Signal;
use Flow\ETL\FlowContext;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

use function Flow\ETL\DSL\array_to_rows;

class DatabaseExtractor extends FlowExtractor
{
    private Builder $query;
    private int $chunkSize;

    /**
     * @param  Builder|Model  $queryOrModel  Query builder or Eloquent model
     * @param  int  $chunkSize  Number of rows to fetch per chunk
     */
    public function __construct(Builder|Model $queryOrModel, int $chunkSize = 1000)
    {
        if ($queryOrModel instanceof Model) {
            $this->query = $queryOrModel::query();
        } else {
            $this->query = $queryOrModel;
        }

        $this->chunkSize = $chunkSize;
    }

    public function extract(FlowContext $context): Generator
    {
        $page = 1;

        while (true) {
            $models = $this->query->forPage($page, $this->chunkSize)->get();

            if ($models->isEmpty()) {
                break;
            }

            $rows = [];

            foreach ($models as $model) {
                $rows[] = $model->toArray();
            }

            $signal = yield array_to_rows($rows, $context->entryFactory());

            if ($signal === Signal::STOP) {
                break;
            }

            $page++;
        }
    }
}
