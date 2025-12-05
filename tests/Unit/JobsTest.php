<?php

use Illuminate\Support\Facades\Bus;
use LaravelIngest\IngestConfig;
use LaravelIngest\Jobs\ProcessIngestChunkJob;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\Models\User;
use Mockery;

it('does not process chunk if batch is cancelled', function () {
    $processorMock = $this->mock(\LaravelIngest\Services\RowProcessor::class);
    $processorMock->shouldNotReceive('processChunk');

    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);

    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, [], false])->makePartial();

    $batchMock = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->once()->andReturn(true);

    $job->shouldReceive('batch')->andReturn($batchMock);

    $job->handle($processorMock);
});
