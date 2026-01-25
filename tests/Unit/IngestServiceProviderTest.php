<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use LaravelIngest\IngestManager;
use LaravelIngest\Tests\Fixtures\AnotherConfigImporter;
use LaravelIngest\Tests\Fixtures\ConfigImporter;

it('registers importers defined in config file', function () {
    Config::set('ingest.importers', [
        'custom-slug' => ConfigImporter::class,
        AnotherConfigImporter::class,
    ]);

    $this->app->forgetInstance(IngestManager::class);

    $manager = $this->app->make(IngestManager::class);
    $definitions = $manager->getDefinitions();

    expect($definitions)->toHaveCount(2)
        ->and($definitions)->toHaveKey('custom-slug')
        ->and($definitions['custom-slug'])->toBeInstanceOf(ConfigImporter::class);

    $expectedSlug = 'another-config-importer';

    expect($definitions)->toHaveKey($expectedSlug)
        ->and($definitions[$expectedSlug])->toBeInstanceOf(AnotherConfigImporter::class);
});
