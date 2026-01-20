<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Contracts\SourceHandler;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\Events\IngestRunCompleted;
use LaravelIngest\Events\IngestRunFailed;
use LaravelIngest\Exceptions\DefinitionNotFoundException;
use LaravelIngest\Exceptions\SourceException;
use LaravelIngest\IngestConfig;
use LaravelIngest\IngestManager;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Sources\SourceHandlerFactory;
use LaravelIngest\Tests\Fixtures\Models\User;
use LaravelIngest\Tests\Fixtures\ProductImporter;

it('throws exception if definition not found directly', function () {
    $manager = new IngestManager([], app(SourceHandlerFactory::class));
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

    $manager = new IngestManager(['emptyimporter' => $definition], app(SourceHandlerFactory::class));
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
    $manager = new IngestManager(['testimporter' => $definition], app(SourceHandlerFactory::class));

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
    $manager = new IngestManager(['failimporter' => $definition], app(SourceHandlerFactory::class));

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
    Event::assertDispatched(IngestRunFailed::class, fn($event) => $event->ingestRun->id === $run->id && $event->exception === $exception);
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
    $manager = new IngestManager(['successimporter' => $definition], app(SourceHandlerFactory::class));

    $run = $manager->start('successimporter', 'users.csv');

    $dispatchedBatches = Bus::dispatchedBatches();
    expect($dispatchedBatches)->toHaveCount(1);
    $batchFake = $dispatchedBatches[0];

    expect($batchFake->options['then'])->toHaveCount(1);
    $callback = $batchFake->options['then'][0];
    $callback($batchFake);

    $run->refresh();

    IngestRow::factory()->create(['ingest_run_id' => $run->id, 'status' => 'success']);

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

    $manager = new IngestManager(['multichunk' => $definition], app(SourceHandlerFactory::class));
    $manager->start('multichunk', 'users.csv');

    Bus::assertBatched(fn($batch) => $batch->jobs->count() === 2);
});

it('handles a failed batch during a retry run', function () {
    Event::fake();
    Bus::fake();

    $originalRun = IngestRun::factory()->create(['importer' => 'userimporter', 'failed_rows' => 1]);
    IngestRow::factory()->create(['ingest_run_id' => $originalRun->id, 'status' => 'failed']);
    $definition = $this->createTestDefinition(IngestConfig::for(User::class));
    $manager = new IngestManager(['userimporter' => $definition], app(SourceHandlerFactory::class));

    $newRun = $manager->retry($originalRun);

    $dispatchedBatches = Bus::dispatchedBatches();
    $batchFake = $dispatchedBatches[0];
    $callback = $batchFake->options['catch'][0];
    $exception = new Exception('Retry job failed!');
    $callback($exception);

    $newRun->refresh();
    expect($newRun->status)->toBe(IngestStatus::FAILED);
    Event::assertDispatched(IngestRunFailed::class, fn($event) => $event->ingestRun->id === $newRun->id);
});

it('completes a retry run immediately if cursor returns empty', function () {
    Event::fake();
    Bus::fake();

    $originalRun = IngestRun::factory()->create(['importer' => 'userimporter', 'failed_rows' => 1]);
    $definition = $this->createTestDefinition(IngestConfig::for(User::class));
    $manager = new IngestManager(['userimporter' => $definition], app(SourceHandlerFactory::class));

    $newRun = $manager->retry($originalRun);

    expect($newRun->status)->toBe(IngestStatus::COMPLETED);
    expect($newRun->refresh()->total_rows)->toBe(0);
    Bus::assertBatchCount(0);
    Event::assertDispatched(IngestRunCompleted::class, fn($event) => $event->ingestRun->id === $newRun->id);
});

it('cleans up source handler on early read failure', function () {
    $sourceHandlerMock = $this->mock(SourceHandler::class);
    $sourceHandlerMock->shouldReceive('read')->once()->andThrow(new SourceException('Read failed unexpectedly'));
    $sourceHandlerMock->shouldReceive('cleanup')->once();

    $factoryMock = $this->mock(SourceHandlerFactory::class);
    $factoryMock->shouldReceive('make')->andReturn($sourceHandlerMock);
    $this->app->instance(SourceHandlerFactory::class, $factoryMock);

    $config = IngestConfig::for(User::class)->fromSource(SourceType::UPLOAD);
    $definition = $this->createTestDefinition($config);
    $manager = new IngestManager(['earlyfail' => $definition], app(SourceHandlerFactory::class));

    $manager->start('earlyfail', 'payload');
})->throws(SourceException::class);

it('creates multiple jobs when retrying rows that exceed chunk size', function () {
    Bus::fake();

    $config = IngestConfig::for(User::class)->setChunkSize(2);
    $definition = $this->createTestDefinition($config);
    $manager = new IngestManager(['multiretry' => $definition], app(SourceHandlerFactory::class));

    $originalRun = IngestRun::factory()->create(['importer' => 'multiretry', 'failed_rows' => 3]);
    IngestRow::factory()->count(3)->create(['ingest_run_id' => $originalRun->id, 'status' => 'failed']);

    $manager->retry($originalRun);

    Bus::assertBatched(fn($batch) => $batch->jobs->count() === 2);
});

it('handles a successful batch for a retry run', function () {
    Event::fake();
    Bus::fake();

    $originalRun = IngestRun::factory()->create(['importer' => 'userimporter', 'failed_rows' => 1]);
    IngestRow::factory()->create(['ingest_run_id' => $originalRun->id, 'status' => 'failed']);
    $definition = $this->createTestDefinition(IngestConfig::for(User::class));
    $manager = new IngestManager(['userimporter' => $definition], app(SourceHandlerFactory::class));

    $newRun = $manager->retry($originalRun);

    $batch = Bus::dispatchedBatches()[0];
    $thenCallback = $batch->options['then'][0];
    $thenCallback($batch);

    $newRun->refresh();
    expect($newRun->status)->toBe(IngestStatus::COMPLETED);
    Event::assertDispatched(IngestRunCompleted::class, fn($event) => $event->ingestRun->id === $newRun->id);
});

it('handles an exception during retry setup', function () {
    Event::fake();
    $manager = new IngestManager([], app(SourceHandlerFactory::class));
    $originalRun = IngestRun::factory()->create(['importer' => 'unknown-importer', 'failed_rows' => 1]);

    try {
        $manager->retry($originalRun);
    } catch (DefinitionNotFoundException $e) {
    }

    $newRun = IngestRun::where('retried_from_run_id', $originalRun->id)->first();
    expect($newRun)->not->toBeNull();
    expect($newRun->status)->toBe(IngestStatus::FAILED);
    expect($newRun->summary['error'])->toContain("No importer found with the slug 'unknown-importer'");

    Event::assertDispatched(IngestRunFailed::class, fn($event) => $event->ingestRun->id === $newRun->id);
});

it('corrects total rows count on retry if it mismatches actual failed rows', function () {
    Bus::fake();

    $originalRun = IngestRun::factory()->create([
        'importer' => 'userimporter',
        'failed_rows' => 5,
    ]);
    IngestRow::factory()->count(3)->create(['ingest_run_id' => $originalRun->id, 'status' => 'failed']);

    $definition = $this->createTestDefinition(IngestConfig::for(User::class));
    $manager = new IngestManager(['userimporter' => $definition], app(SourceHandlerFactory::class));

    $newRun = $manager->retry($originalRun);
    $newRun->refresh();

    expect($newRun->total_rows)->toBe(3);
    Bus::assertBatched(fn($batch) => $batch->jobs->count() === 1);
});
