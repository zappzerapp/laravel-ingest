<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Unit\Flow\Extractors\Fixtures;

use Flow\ETL\FlowContext;
use LaravelIngest\Flow\Extractors\Fixtures\TestExtractor;

it('extracts test rows with id and name', function () {
    $extractor = new TestExtractor();
    $context = new FlowContext(\Flow\ETL\Config::default());

    $rows = iterator_to_array($extractor->extract($context));

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBeInstanceOf(\Flow\ETL\Rows::class);
    expect($rows[0]->count())->toBe(1);

    $row = $rows[0]->first();
    expect($row->get('id')->value())->toBe(1);
    expect($row->get('name')->value())->toBe('Test');
});

it('extends FlowExtractor', function () {
    $extractor = new TestExtractor();

    expect($extractor)->toBeInstanceOf(\LaravelIngest\Flow\Extractors\FlowExtractor::class);
});
