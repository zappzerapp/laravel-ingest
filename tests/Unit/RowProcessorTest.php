<?php

use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\IngestConfig;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\Models\User;

beforeEach(function () {
    $this->processor = new RowProcessor();
});

it('successfully processes a valid row', function () {
    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->map('name', 'name')
        ->map('email', 'email');

    $rowData = ['name' => 'John Doe', 'email' => 'john@example.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk(
        \LaravelIngest\Models\IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['email' => 'john@example.com', 'name' => 'John Doe']);
});

it('throws a validation exception for an invalid row', function () {
    $config = IngestConfig::for(User::class)->validate(['email' => 'required|email']);
    $rowData = ['email' => 'not-an-email'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $run = \LaravelIngest\Models\IngestRun::factory()->create();
    $results = $this->processor->processChunk(
        $run,
        $config,
        $chunk,
        false
    );

    expect($results['failed'])->toBe(1);
    expect($results['successful'])->toBe(0);

    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $run->id,
        'row_number' => 1,
        'status' => 'failed'
    ]);
});

it('updates a duplicate row when strategy is update', function () {
    $user = User::create(['name' => 'Old Name', 'email' => 'jane@example.com']);
    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::UPDATE)
        ->map('name', 'name')
        ->map('email', 'email');

    $rowData = ['name' => 'Jane Doe Updated', 'email' => 'jane@example.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk(
        \LaravelIngest\Models\IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Jane Doe Updated']);
});

it('skips a duplicate row when strategy is skip', function () {
    User::create(['name' => 'Old Name', 'email' => 'skip@example.com']);
    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::SKIP)
        ->map('name', 'name')
        ->map('email', 'email');

    $rowData = ['name' => 'New Name', 'email' => 'skip@example.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk(
        \LaravelIngest\Models\IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'skip@example.com')->first();
    expect($user->name)->toBe('Old Name');
});

it('does not persist data on a dry run', function () {
    $config = IngestConfig::for(User::class)->map('name', 'name')->map('email', 'email');
    $rowData = ['name' => 'Dry Run User', 'email' => 'dry@run.com'];
    $chunk = [['number' => 1, 'data' => $rowData]];

    $this->processor->processChunk(
        \LaravelIngest\Models\IngestRun::factory()->create(),
        $config,
        $chunk,
        true
    );

    $this->assertDatabaseMissing('users', ['email' => 'dry@run.com']);
});

it('logs an error row when duplicate strategy is fail', function () {
    User::create(['email' => 'duplicate@example.com', 'name' => 'Original']);

    $config = IngestConfig::for(User::class)
        ->keyedBy('email')
        ->onDuplicate(DuplicateStrategy::FAIL)
        ->map('email', 'email');

    $run = \LaravelIngest\Models\IngestRun::factory()->create();

    $results = $this->processor->processChunk(
        $run,
        $config,
        [['number' => 1, 'data' => ['email' => 'duplicate@example.com']]],
        false
    );

    expect($results['failed'])->toBe(1);

    $this->assertDatabaseHas('ingest_rows', [
        'ingest_run_id' => $run->id,
        'status' => 'failed',
    ]);

    expect(User::first()->name)->toBe('Original');
});