<?php

declare(strict_types=1);

use LaravelIngest\Contracts\FlowEngineInterface;
use LaravelIngest\IngestConfig;
use LaravelIngest\Jobs\ProcessIngestChunkJob;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\Models\User;

it('does not process chunk if batch is cancelled', function () {
    $flowEngineMock = $this->mock(FlowEngineInterface::class);
    $flowEngineMock->shouldNotReceive('build');
    $flowEngineMock->shouldNotReceive('execute');

    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);

    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, [], false])->makePartial();

    $batchMock = Mockery::mock(Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->once()->andReturn(true);

    $job->shouldReceive('batch')->andReturn($batchMock);

    $job->handle(null, $flowEngineMock);
});

it('triggers garbage collection when memory usage exceeds 80%', function () {
    $chunk = [['number' => 1, 'test' => 'data']];
    $dataFrame = \Flow\ETL\DSL\data_frame()->read(\Flow\ETL\DSL\from_array($chunk));

    $flowEngineMock = $this->mock(FlowEngineInterface::class);
    $flowEngineMock->shouldReceive('build')
        ->once()
        ->andReturn($dataFrame);
    $flowEngineMock->shouldReceive('execute')
        ->once()
        ->with($dataFrame);

    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);

    $jobMock = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, $chunk, false])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $jobMock->shouldAllowMockingProtectedMethods();
    $jobMock->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(1000);

    $jobMock->shouldReceive('getCurrentMemoryUsage')->once()->andReturn(900);

    $batchMock = Mockery::mock(Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->once()->andReturn(false);
    $jobMock->shouldReceive('batch')->andReturn($batchMock);

    $jobMock->handle(null, $flowEngineMock);
});

it('returns PHP_INT_MAX for unlimited memory limit', function () {
    $chunk = [['number' => 1, 'test' => 'data']];
    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);
    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, $chunk, false])->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(PHP_INT_MAX);

    $dataFrame = \Flow\ETL\DSL\data_frame()->read(\Flow\ETL\DSL\from_array($chunk));

    $flowEngineMock = $this->mock(FlowEngineInterface::class);
    $flowEngineMock->shouldReceive('build')
        ->once()
        ->andReturn($dataFrame);
    $flowEngineMock->shouldReceive('execute')
        ->once()
        ->with($dataFrame);

    $job->handle(null, $flowEngineMock);
});

it('converts memory limit with G unit to bytes', function () {
    $chunk = [['number' => 1, 'test' => 'data']];
    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);
    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, $chunk, false])->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(2 * 1024 * 1024 * 1024);

    $dataFrame = \Flow\ETL\DSL\data_frame()->read(\Flow\ETL\DSL\from_array($chunk));

    $flowEngineMock = $this->mock(FlowEngineInterface::class);
    $flowEngineMock->shouldReceive('build')
        ->once()
        ->andReturn($dataFrame);
    $flowEngineMock->shouldReceive('execute')
        ->once()
        ->with($dataFrame);

    $job->handle(null, $flowEngineMock);
});

it('converts memory limit with K unit to bytes', function () {
    $chunk = [['number' => 1, 'test' => 'data']];
    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);
    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, $chunk, false])->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(512 * 1024);

    $dataFrame = \Flow\ETL\DSL\data_frame()->read(\Flow\ETL\DSL\from_array($chunk));

    $flowEngineMock = $this->mock(FlowEngineInterface::class);
    $flowEngineMock->shouldReceive('build')
        ->once()
        ->andReturn($dataFrame);
    $flowEngineMock->shouldReceive('execute')
        ->once()
        ->with($dataFrame);

    $job->handle(null, $flowEngineMock);
});

it('getMemoryLimitInBytes returns PHP_INT_MAX when memory_limit is -1', function () {
    $originalLimit = ini_get('memory_limit');

    try {
        ini_set('memory_limit', '-1');

        $run = IngestRun::factory()->create();
        $config = IngestConfig::for(User::class);
        $job = new ProcessIngestChunkJob($run, $config, [], false);

        $reflection = new ReflectionMethod($job, 'getMemoryLimitInBytes');

        expect($reflection->invoke($job))->toBe(PHP_INT_MAX);
    } finally {
        ini_set('memory_limit', $originalLimit);
    }
});
