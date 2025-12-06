<?php

use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Models\IngestRun;

it('shows the status of a specific ingest run', function () {
    $run = IngestRun::factory()->create([
        'importer_slug' => 'user-importer',
        'status' => IngestStatus::COMPLETED,
        'total_rows' => 100,
        'processed_rows' => 100,
        'successful_rows' => 95,
        'failed_rows' => 5,
    ]);

    $this->artisan('ingest:status', ['ingestRun' => $run->id])
        ->expectsOutputToContain("Details for Ingest Run #{$run->id}")
        ->expectsOutputToContain('user-importer')
        ->expectsOutputToContain(IngestStatus::COMPLETED->value)
        ->expectsTable(
            ['Total', 'Processed', 'Successful', 'Failed'],
            [['100', '100', '95', '5']]
        )
        ->assertExitCode(0);
});

it('shows an error if the ingest run for status check does not exist', function () {
    $this->artisan('ingest:status', ['ingestRun' => 999])
        ->expectsOutputToContain('No ingest run found with ID 999.')
        ->assertExitCode(1);
});

it('shows the failure reason if the run failed', function () {
    $run = IngestRun::factory()->create([
        'status' => IngestStatus::FAILED,
        'summary' => ['error' => 'A critical error occurred.'],
    ]);

    $this->artisan('ingest:status', ['ingestRun' => $run->id])
        ->expectsOutputToContain('Failure Reason:')
        ->expectsOutputToContain('A critical error occurred.')
        ->assertExitCode(0);
});

it('shows a progress bar for a processing run', function () {
    $run = IngestRun::factory()->create([
        'status' => IngestStatus::PROCESSING,
        'total_rows' => 100,
        'processed_rows' => 25,
    ]);

    $this->artisan('ingest:status', ['ingestRun' => $run->id])
        ->expectsOutputToContain('25%') // The progress bar component will output the percentage
        ->assertExitCode(0);
});