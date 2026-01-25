<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use LaravelIngest\IngestManager;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\UserImporter;

it('can retry a failed ingest run', function () {
    Bus::fake();
    $this->app->tag([UserImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);

    $originalRun = IngestRun::factory()->create([
        'importer' => 'userimporter',
        'failed_rows' => 2,
    ]);
    IngestRow::factory()->create([
        'ingest_run_id' => $originalRun->id,
        'status' => 'failed',
        'data' => ['full_name' => 'Retry User 1', 'user_email' => 'retry1@test.com'],
    ]);
    IngestRow::factory()->create([
        'ingest_run_id' => $originalRun->id,
        'status' => 'failed',
        'data' => ['full_name' => 'Retry User 2', 'user_email' => 'retry2@test.com'],
    ]);
    IngestRow::factory()->create(['ingest_run_id' => $originalRun->id, 'status' => 'success']);

    $this->artisan('ingest:retry', ['ingestRun' => $originalRun->id])
        ->expectsOutputToContain('Retry run successfully queued.')
        ->assertExitCode(0);

    $this->assertDatabaseCount('ingest_runs', 2);
    $newRun = IngestRun::where('retried_from_run_id', $originalRun->id)->first();
    expect($newRun)->not()->toBeNull()
        ->and($newRun->total_rows)->toBe(2);

    Bus::assertBatched(fn($batch) => $batch->jobs->count() === 1);
});

it('can retry a failed ingest run in dry-run mode', function () {
    Bus::fake();
    $this->app->tag([UserImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);

    $originalRun = IngestRun::factory()->create([
        'importer' => 'userimporter',
        'failed_rows' => 1,
    ]);
    IngestRow::factory()->create(['ingest_run_id' => $originalRun->id, 'status' => 'failed']);

    $this->artisan('ingest:retry', [
        'ingestRun' => $originalRun->id,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Running in DRY-RUN mode. No changes will be saved to the database.')
        ->expectsOutputToContain('Retry run successfully queued.')
        ->assertExitCode(0);

    Bus::assertBatched(function ($batch) {
        $job = $batch->jobs->first();

        return $job->isDryRun === true;
    });
});

it('shows a warning if there are no failed rows to retry', function () {
    $run = IngestRun::factory()->create(['failed_rows' => 0]);

    $this->artisan('ingest:retry', ['ingestRun' => $run->id])
        ->expectsOutputToContain('The original run has no failed rows to retry.')
        ->assertExitCode(0);
});

it('shows an error if the original run does not exist', function () {
    $this->artisan('ingest:retry', ['ingestRun' => 999])
        ->expectsOutputToContain('No ingest run found with ID 999.')
        ->assertExitCode(1);
});

it('shows an error if the retry process fails', function () {
    $originalRun = IngestRun::factory()->create(['failed_rows' => 1]);

    $this->mock(IngestManager::class)
        ->shouldReceive('retry')
        ->once()
        ->andThrow(new Exception('A critical error occurred'));

    $this->artisan('ingest:retry', ['ingestRun' => $originalRun->id])
        ->expectsOutputToContain('The retry process could not be started.')
        ->expectsOutputToContain('A critical error occurred')
        ->assertExitCode(1);
});
