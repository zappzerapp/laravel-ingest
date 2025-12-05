<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Events\IngestRunCompleted;
use LaravelIngest\Events\IngestRunFailed;
use LaravelIngest\Exceptions\DefinitionNotFoundException;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\IngestManager;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Tests\Fixtures\Models\User;
use LaravelIngest\Tests\Fixtures\ProductImporter;

it('throws exception if definition not found directly', function () {
    $manager = new IngestManager([]);
    $manager->getDefinition('non-existent');
})->throws(DefinitionNotFoundException::class);

it('completes successfully with no jobs if source is empty', function () {
    Event::fake();
    Bus::fake();
    Storage::fake('local');
    Storage::put('users.csv', "full_name,user_email,is_admin\n");

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'users.csv']);

    $definition = $this->createTestDefinition($config);

    $manager = new IngestManager(['emptyimporter' => $definition]);
    $run = $manager->start('emptyimporter', 'users.csv');

    Bus::assertBatchCount(0);

    expect($run->status)->toBe(IngestStatus::COMPLETED);
    Event::assertDispatched(IngestRunCompleted::class);
});

it('throws source exception if keyedBy column is missing in header', function () {
    Storage::fake('local');
    Storage::put('users.csv', "full_name,email_address,is_admin\nJohn,john@doe.com,yes");

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'users.csv'])
        ->keyedBy('user_email');

    $definition = $this->createTestDefinition($config);
    $manager = new IngestManager(['testimporter' => $definition]);

    $manager->start('testimporter', 'users.csv');

})->throws(SourceException::class, "The key column 'user_email' was not found in the source file headers.");


it('handles a failed batch correctly', function () {
    Event::fake();
    Bus::fake();
    Storage::fake('local');
    Storage::put('users.csv', "full_name,user_email\nTest,test@test.com");

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'users.csv'])
        ->map('user_email', 'email');

    $definition = $this->createTestDefinition($config);
    $manager = new IngestManager(['failimporter' => $definition]);

    $run = $manager->start('failimporter', 'users.csv');

    $dispatchedBatches = Bus::dispatchedBatches();
    expect($dispatchedBatches)->toHaveCount(1);
    $batchFake = $dispatchedBatches[0];

    expect($batchFake->options['catch'])->toHaveCount(1);

    $exception = new Exception('Simulated job failure!');
    $callback = $batchFake->options['catch'][0];
    $callback($exception);

    $run->refresh();
    expect($run->status)->toBe(IngestStatus::FAILED);
    Event::assertDispatched(IngestRunFailed::class, function ($event) use ($run, $exception) {
        return $event->ingestRun->id === $run->id && $event->exception === $exception;
    });
});

it('dispatches failed event when source is unavailable', function () {
    Event::fake();
    Storage::fake('local');

    $this->app->tag([ProductImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);

    $manager = app(IngestManager::class);

    try {
        $manager->start('productimporter');
    } catch (SourceException $e) {
    }

    Event::assertDispatched(IngestRunFailed::class);
});

it('handles a successful batch correctly', function () {
    Event::fake();
    Bus::fake();
    Storage::fake('local');
    Storage::put('users.csv', "full_name,user_email\nTest,test@test.com");

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'users.csv'])
        ->map('user_email', 'email');

    $definition = $this->createTestDefinition($config);
    $manager = new IngestManager(['successimporter' => $definition]);

    $run = $manager->start('successimporter', 'users.csv');

    $dispatchedBatches = Bus::dispatchedBatches();
    expect($dispatchedBatches)->toHaveCount(1);
    $batchFake = $dispatchedBatches[0];

    expect($batchFake->options['then'])->toHaveCount(1);
    $callback = $batchFake->options['then'][0];
    $callback($batchFake);

    $run->refresh();

    \LaravelIngest\Models\IngestRow::factory()->create(['ingest_run_id' => $run->id, 'status' => 'success']);

    $run->finalize();
    $run->refresh();

    expect($run->status)->toBe(IngestStatus::COMPLETED);
    Event::assertDispatched(IngestRunCompleted::class);
});

it('creates multiple jobs when source rows exceed chunk size', function () {
    Bus::fake();
    Storage::fake('local');
    Storage::put('users.csv', "email\ntest1@test.com\ntest2@test.com\ntest3@test.com");

    $config = IngestConfig::for(User::class)
        ->fromSource(SourceType::FILESYSTEM, ['path' => 'users.csv'])
        ->setChunkSize(2);

    $definition = $this->createTestDefinition($config);

    $manager = new IngestManager(['multichunk' => $definition]);
    $manager->start('multichunk', 'users.csv');

    Bus::assertBatched(function ($batch) {
        return $batch->jobs->count() === 2;
    });
});