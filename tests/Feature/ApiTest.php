<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\IngestServiceProvider;
use LaravelIngest\Jobs\ProcessIngestChunkJob;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\ProductImporter;
use LaravelIngest\Tests\Fixtures\UserImporter;

beforeEach(function () {
    $this->app->tag([UserImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
    Storage::fake('local');
});

it('can upload a file and start an ingest run', function () {
    Bus::fake();

    $fileContent = "full_name,user_email,is_admin\nJohn Doe,john@example.com,yes\nJane Doe,jane@example.com,no";
    $file = UploadedFile::fake()->createWithContent('users.csv', $fileContent);

    $response = $this->postJson('/api/v1/ingest/upload/userimporter', [
        'file' => $file,
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.importer', 'userimporter')
        ->assertJsonPath('data.status', IngestStatus::PROCESSING->value);

    Bus::assertBatched(fn($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof ProcessIngestChunkJob);

    $dispatchedBatches = Bus::dispatchedBatches();
    $batch = $dispatchedBatches[0];
    $job = $batch->jobs->first();

    app()->make(RowProcessor::class)->processChunk(
        $job->ingestRun,
        $job->config,
        $job->chunk,
        $job->isDryRun
    );

    $this->assertDatabaseCount('ingest_runs', 1);
    $this->assertDatabaseHas('users', ['email' => 'john@example.com', 'is_admin' => true]);
    $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'is_admin' => false]);
});

it('returns a list of ingest runs', function () {
    IngestRun::factory()->count(3)->create(['importer' => 'userimporter']);

    $this->getJson('/api/v1/ingest')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('returns a single ingest run with details', function () {
    $run = IngestRun::factory()->create();
    IngestRow::factory()->count(5)->create(['ingest_run_id' => $run->id]);

    $this->getJson("/api/v1/ingest/{$run->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $run->id)
        ->assertJsonCount(5, 'data.rows');
});

it('rejects upload without a file', function () {
    $this->postJson('/api/v1/ingest/upload/userimporter')
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('can trigger a non-upload ingest run', function () {
    $this->app->tag([ProductImporter::class], IngestServiceProvider::INGEST_DEFINITION_TAG);
    Storage::fake('local');
    Storage::put('products.csv', "product_sku,product_name,quantity\nAPI-001,API Product,50");

    $response = $this->postJson('/api/v1/ingest/trigger/productimporter');

    $response->assertStatus(202)
        ->assertJsonPath('data.importer', 'productimporter')
        ->assertJsonPath('data.status', IngestStatus::PROCESSING->value);

    $this->assertDatabaseHas('products', ['sku' => 'API-001']);
});

it('can cancel an ingest run via api', function () {
    Bus::fake();
    $batch = Bus::batch([])->dispatch();
    $run = IngestRun::factory()->create(['batch_id' => $batch->id]);

    $this->postJson("/api/v1/ingest/{$run->id}/cancel")
        ->assertOk()
        ->assertJson(['message' => 'Cancellation request sent.']);

    expect($batch->fresh()->cancelled())->toBeTrue();
});

it('can retry a failed ingest run via api', function () {
    Bus::fake();
    $originalRun = IngestRun::factory()->create([
        'importer' => 'userimporter',
        'failed_rows' => 1,
    ]);
    IngestRow::factory()->create(['ingest_run_id' => $originalRun->id, 'status' => 'failed']);

    $this->postJson("/api/v1/ingest/{$originalRun->id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('data.progress.total', 1);

    $this->assertDatabaseHas('ingest_runs', ['retried_from_run_id' => $originalRun->id]);
    Bus::assertBatched(fn($batch) => $batch->jobs->count() > 0);
});

it('returns bad request when retrying a run with no failed rows via api', function () {
    $run = IngestRun::factory()->create(['failed_rows' => 0]);

    $this->postJson("/api/v1/ingest/{$run->id}/retry")
        ->assertStatus(400)
        ->assertJson(['message' => 'The original run has no failed rows to retry.']);
});

it('can get an aggregated error summary for a failed run', function () {
    $run = IngestRun::factory()->create();

    IngestRow::factory()->count(2)->create([
        'ingest_run_id' => $run->id,
        'status' => 'failed',
        'errors' => ['message' => 'Duplicate entry found.'],
    ]);

    IngestRow::factory()->count(2)->create([
        'ingest_run_id' => $run->id,
        'status' => 'failed',
        'errors' => [
            'message' => 'The given data was invalid.',
            'validation' => ['user_email' => ['The user email field is required.']],
        ],
    ]);

    IngestRow::factory()->create([
        'ingest_run_id' => $run->id,
        'status' => 'failed',
        'errors' => [
            'message' => 'The given data was invalid.',
            'validation' => ['full_name' => ['The full name field is required.']],
        ],
    ]);

    $response = $this->getJson("/api/v1/ingest/{$run->id}/errors/summary");

    $response->assertOk()
        ->assertJsonPath('data.total_failed_rows', 5)
        ->assertJsonPath('data.error_summary.0.message', 'The given data was invalid.')
        ->assertJsonPath('data.error_summary.0.count', 3)
        ->assertJsonPath('data.error_summary.1.message', 'Duplicate entry found.')
        ->assertJsonPath('data.error_summary.1.count', 2)
        ->assertJsonPath('data.validation_summary.0.message', 'user_email: The user email field is required.')
        ->assertJsonPath('data.validation_summary.0.count', 2)
        ->assertJsonPath('data.validation_summary.1.message', 'full_name: The full name field is required.')
        ->assertJsonPath('data.validation_summary.1.count', 1);
});

it('gracefully ignores rows with malformed error data in summary', function () {
    $run = IngestRun::factory()->create();

    IngestRow::factory()->create([
        'ingest_run_id' => $run->id,
        'status' => 'failed',
        'errors' => ['message' => 'A valid error.'],
    ]);

    IngestRow::factory()->create([
        'ingest_run_id' => $run->id,
        'status' => 'failed',
        'errors' => null,
    ]);

    $response = $this->getJson("/api/v1/ingest/{$run->id}/errors/summary");

    $response->assertOk()
        ->assertJsonPath('data.total_failed_rows', 2)
        ->assertJsonPath('data.error_summary.0.message', 'A valid error.')
        ->assertJsonPath('data.error_summary.0.count', 1)
        ->assertJsonCount(1, 'data.error_summary');
});
