<?php

declare(strict_types=1);

use LaravelIngest\Services\MemoryLimitService;

function runInProduction(Closure $callback)
{
    $app = app();
    $oldEnv = $app['env'];
    $app['env'] = 'production';

    try {
        $callback();
    } finally {
        $app['env'] = $oldEnv;
    }
}

it('execute callback with memory check outside testing', function () {
    $service = new MemoryLimitService();

    $result = $service->executeWithLimit(fn() => 'test-result', 1024);

    expect($result)->toBe('test-result');
});

it('processes chunks with small data', function () {
    $service = new MemoryLimitService();

    $items = [[1], [2], [3]];
    $processed = [];

    $service->processInChunks($items, 2, function ($chunk) use (&$processed) {
        $processed[] = $chunk;
    }, 1024);

    expect($processed)->toHaveCount(2);
});

it('gets memory statistics', function () {
    $service = new MemoryLimitService();

    $stats = $service->getMemoryStats();

    expect($stats)->toHaveKeys([
        'start_memory',
        'current_memory',
        'peak_memory',
        'memory_limit',
    ]);
});

it('checks memory limit exceeded during testing returns false', function () {
    $service = new MemoryLimitService();

    $isExceeded = $service->isMemoryLimitExceeded(512);

    expect($isExceeded)->toBeFalse();
});

it('resets memory tracking', function () {
    $service = new MemoryLimitService();

    $statsBefore = $service->getMemoryStats();
    $service->reset();

    $statsAfter = $service->getMemoryStats();

    expect($statsAfter['start_memory'])->toBeGreaterThanOrEqual($statsBefore['start_memory']);
});

it('parses memory limit with G unit', function () {
    $originalLimit = ini_get('memory_limit');
    ini_set('memory_limit', '1G');

    try {
        $service = new MemoryLimitService();
        $stats = $service->getMemoryStats();
        expect($stats['memory_limit'])->toBe(1073741824);
    } finally {
        ini_set('memory_limit', $originalLimit);
    }
});

it('parses memory limit with K unit', function () {
    $originalLimit = ini_get('memory_limit');
    ini_set('memory_limit', '1048576K');

    try {
        $service = new MemoryLimitService();
        $stats = $service->getMemoryStats();
        expect($stats['memory_limit'])->toBe(1073741824);
    } finally {
        ini_set('memory_limit', $originalLimit);
    }
});

it('parses memory limit without unit', function () {
    $originalLimit = ini_get('memory_limit');
    ini_set('memory_limit', '1073741824');

    try {
        $service = new MemoryLimitService();
        $stats = $service->getMemoryStats();
        expect($stats['memory_limit'])->toBe(1073741824);
    } finally {
        ini_set('memory_limit', $originalLimit);
    }
});

it('throws exception if memory limit exceeded in production', function () {
    runInProduction(function () {
        $originalLimit = ini_get('memory_limit');

        $current = memory_get_usage(true);
        $limit = $current + 1024 * 1024;
        ini_set('memory_limit', (string) $limit);

        try {
            $service = new MemoryLimitService();

            $service->executeWithLimit(fn() => true, 2);
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Insufficient memory available for operation.');

            return;
        } finally {
            ini_set('memory_limit', $originalLimit);
        }

        throw new Exception('Should have thrown RuntimeException');
    });
});

it('throws exception if chunk processing exceeds limit in production', function () {
    runInProduction(function () {
        $originalLimit = ini_get('memory_limit');

        $current = memory_get_usage(true);
        $limit = $current + 10 * 1024 * 1024;
        ini_set('memory_limit', (string) $limit);

        try {
            $service = new MemoryLimitService();

            $data = array_fill(0, 5, 'item');
            $leak = [];

            $service->processInChunks($data, 1, function ($chunk) use (&$leak) {
                $leak[] = str_repeat('a', (int) (9.5 * 1024 * 1024));
            }, 1);

        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Memory limit exceeded during chunk processing.');

            return;
        } finally {
            ini_set('memory_limit', $originalLimit);
            unset($leak);
        }

        throw new Exception('Should have thrown RuntimeException');
    });
});

it('triggers garbage collection in production', function () {
    runInProduction(function () {
        $originalLimit = ini_get('memory_limit');

        $current = memory_get_usage(true);
        $limit = (int) ($current / 0.85);
        ini_set('memory_limit', (string) $limit);

        try {
            $service = new MemoryLimitService();

            $service->executeWithLimit(fn() => true, 0);

            expect(true)->toBeTrue();
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
    });
});
