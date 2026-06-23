<?php

declare(strict_types=1);

use Flow\ETL\Config as EtlConfig;
use Flow\ETL\Extractor;
use Flow\ETL\FlowContext;
use Flow\ETL\Row;
use Flow\ETL\Row\Entry\IntegerEntry;
use Flow\ETL\Row\Entry\StringEntry;
use Flow\ETL\Rows;
use LaravelIngest\Flow\Extractors\FlowExtractor;

it('implements the Extractor interface', function () {
    $extractor = new class() extends FlowExtractor
    {
        public function extract(FlowContext $context): Generator
        {
            yield new Rows();
        }
    };

    expect($extractor)->toBeInstanceOf(Extractor::class);
});

it('generates rows in extract method', function () {
    $extractor = new class() extends FlowExtractor
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
    };

    $context = new FlowContext(EtlConfig::default());
    $rows = iterator_to_array($extractor->extract($context));

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBeInstanceOf(Rows::class);
});

it('can yield multiple batches of rows', function () {
    $extractor = new class() extends FlowExtractor
    {
        public function extract(FlowContext $context): Generator
        {
            yield new Rows(
                Row::create(new IntegerEntry('id', 1), new StringEntry('name', 'First'))
            );
            yield new Rows(
                Row::create(new IntegerEntry('id', 2), new StringEntry('name', 'Second'))
            );
        }
    };

    $context = new FlowContext(EtlConfig::default());
    $batches = iterator_to_array($extractor->extract($context));

    expect($batches)->toHaveCount(2);
    expect($batches[0])->toBeInstanceOf(Rows::class);
    expect($batches[1])->toBeInstanceOf(Rows::class);
});

it('can yield empty rows', function () {
    $extractor = new class() extends FlowExtractor
    {
        public function extract(FlowContext $context): Generator
        {
            yield new Rows();
        }
    };

    $context = new FlowContext(EtlConfig::default());
    $rows = iterator_to_array($extractor->extract($context));

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBeInstanceOf(Rows::class);
    expect($rows[0]->count())->toBe(0);
});
