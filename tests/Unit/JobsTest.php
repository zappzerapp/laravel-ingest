<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\Jobs\ProcessIngestChunkJob;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\Models\User;

it('does not process chunk if batch is cancelled', function () {
    $processorMock = $this->mock(LaravelIngest\Services\RowProcessor::class);
    $processorMock->shouldNotReceive('processChunk');

    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);

    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, [], false])->makePartial();

    $batchMock = Mockery::mock(Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->once()->andReturn(true);

    $job->shouldReceive('batch')->andReturn($batchMock);

    $job->handle($processorMock);
});

it('triggers garbage collection when memory usage exceeds 80%', function () {
    $processorMock = $this->mock(LaravelIngest\Services\RowProcessor::class);
    $processorMock->shouldReceive('processChunk')->once()->andReturn([
        'processed' => 1,
        'successful' => 1,
        'failed' => 0,
    ]);

    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);

    // Create a partial mock directly – not from an instance
    $jobMock = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, [['test' => 'data']], false])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    // Mock the memory limit to a small value for testing (1000 bytes)
    $jobMock->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(1000);

    // Mock current memory usage to return 900 bytes (90%)
    $jobMock->shouldReceive('getCurrentMemoryUsage')->once()->andReturn(900);

    $batchMock = Mockery::mock(Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->once()->andReturn(false);
    $jobMock->shouldReceive('batch')->andReturn($batchMock);

    $jobMock->handle($processorMock);
});

it('returns PHP_INT_MAX for unlimited memory limit', function () {
    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);
    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, [], false])->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    // Mock the getMemoryLimitInBytes method
    $job->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(PHP_INT_MAX);

    $processorMock = $this->mock(LaravelIngest\Services\RowProcessor::class);
    $processorMock->shouldReceive('processChunk')->once()->andReturn([
        'processed' => 1,
        'successful' => 1,
        'failed' => 0,
    ]);

    $job->handle($processorMock);
});

it('converts memory limit with G unit to bytes', function () {
    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);
    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, [], false])->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    // Mock the getMemoryLimitInBytes method
    $job->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(2 * 1024 * 1024 * 1024);

    $processorMock = $this->mock(LaravelIngest\Services\RowProcessor::class);
    $processorMock->shouldReceive('processChunk')->once()->andReturn([
        'processed' => 1,
        'successful' => 1,
        'failed' => 0,
    ]);

    $job->handle($processorMock);
});

it('converts memory limit with K unit to bytes', function () {
    $run = IngestRun::factory()->create();
    $config = IngestConfig::for(User::class);
    $job = Mockery::mock(ProcessIngestChunkJob::class, [$run, $config, [], false])->makePartial();
    $job->shouldAllowMockingProtectedMethods();

    // Mock the getMemoryLimitInBytes method
    $job->shouldReceive('getMemoryLimitInBytes')->once()->andReturn(512 * 1024);

    $processorMock = $this->mock(LaravelIngest\Services\RowProcessor::class);
    $processorMock->shouldReceive('processChunk')->once()->andReturn([
        'processed' => 1,
        'successful' => 1,
        'failed' => 0,
    ]);

    $job->handle($processorMock);
});

it('getMemoryLimitInBytes returns PHP_INT_MAX when memory_limit is -1', function () {
    $originalLimit = ini_get('memory_limit');

    try {
        ini_set('memory_limit', '-1');

        $run = IngestRun::factory()->create();
        $config = IngestConfig::for(User::class);
        $job = new ProcessIngestChunkJob($run, $config, [], false);

        // Use reflection to call the protected method
        $reflection = new ReflectionMethod($job, 'getMemoryLimitInBytes');
        $reflection->setAccessible(true);

        expect($reflection->invoke($job))->toBe(PHP_INT_MAX);
    } finally {
        ini_set('memory_limit', $originalLimit);
    }
});
