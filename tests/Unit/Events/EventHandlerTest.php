<?php

declare(strict_types=1);

use LaravelIngest\Contracts\ImportEventHandlerInterface;
use LaravelIngest\DTOs\RowData;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Tests\Fixtures\Models\Product;
use LaravelIngest\ValueObjects\ImportStats;

class TestEventHandler implements ImportEventHandlerInterface
{
    public array $events = [];

    public function beforeImport(IngestRun $run): void
    {
        $this->events[] = ['type' => 'beforeImport', 'run_id' => $run->id];
    }

    public function onRowProcessed(IngestRun $run, RowData $row, object $model): void
    {
        $this->events[] = [
            'type' => 'onRowProcessed',
            'run_id' => $run->id,
            'row_id' => $row->rowNumber,
        ];
    }

    public function onError(IngestRun $run, RowData $row, Throwable $error): void
    {
        $this->events[] = [
            'type' => 'onError',
            'run_id' => $run->id,
            'error' => $error->getMessage(),
        ];
    }

    public function afterImport(IngestRun $run, ImportStats $stats): void
    {
        $this->events[] = [
            'type' => 'afterImport',
            'run_id' => $run->id,
            'total_rows' => $stats->totalRows,
        ];
    }
}

it('registers event handler on config', function () {
    $handler = new TestEventHandler();

    $config = IngestConfig::for(Product::class)
        ->withEventHandler($handler);

    expect($config->eventHandler)->toBe($handler);
});

it('event handler is optional', function () {
    $config = IngestConfig::for(Product::class);

    expect($config->eventHandler)->toBeNull();
});
