<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use LaravelIngest\Models\IngestRun;

it('cancels a running ingest run', function () {
    Bus::fake();

    $batch = Bus::batch([])->dispatch();
    $run = IngestRun::factory()->create(['batch_id' => $batch->id]);

    $this->artisan('ingest:cancel', ['ingestRun' => $run->id])
        ->expectsOutputToContain("Cancellation request sent for ingest run #{$run->id}.")
        ->assertExitCode(0);

    expect($batch->fresh()->cancelled())->toBeTrue();
});

it('shows an error if the ingest run does not exist', function () {
    $this->artisan('ingest:cancel', ['ingestRun' => 999])
        ->expectsOutputToContain('No ingest run found with ID 999.')
        ->assertExitCode(1);
});

it('shows a warning if the batch does not exist', function () {
    $run = IngestRun::factory()->create(['batch_id' => null]);

    $this->artisan('ingest:cancel', ['ingestRun' => $run->id])
        ->expectsOutputToContain("Could not find a batch associated with run ID {$run->id}.")
        ->assertExitCode(1);
});

it('shows a message if the batch has already finished', function () {
    // FIX: Set a non-null batch_id on the factory
    $run = IngestRun::factory()->create(['batch_id' => 'test-batch-id-123']);

    $batchMock = $this->mock(Batch::class);
    $batchMock->shouldReceive('finished')->once()->andReturn(true);

    // FIX: Ensure the Bus facade returns the mock for the correct batch_id
    Bus::shouldReceive('findBatch')->with($run->batch_id)->andReturn($batchMock);

    $this->artisan('ingest:cancel', ['ingestRun' => $run->id])
        ->expectsOutputToContain("Ingest run #{$run->id} has already finished.")
        ->assertExitCode(0);
});
