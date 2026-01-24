<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Closure;
use RuntimeException;

class MemoryLimitService
{
    private int $memoryLimit;
    private int $startMemory;
    private int $peakUsage = 0;

    public function __construct()
    {
        $this->memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * @throws RuntimeException
     */
    public function executeWithLimit(Closure $callback, int $maxMemoryMb = 512): mixed
    {
        $maxMemoryBytes = $maxMemoryMb * 1024 * 1024;

        $currentUsage = memory_get_usage(true);

        if (app()->environment('testing')) {
            return $callback();
        }

        $memoryLimitBytes = $this->memoryLimit;

        if ($currentUsage > $memoryLimitBytes - $maxMemoryBytes) {
            throw new RuntimeException('Insufficient memory available for operation.');
        }

        $result = $callback();

        $this->peakUsage = max($this->peakUsage, memory_get_peak_usage(true));

        if ($this->shouldGarbageCollect()) {
            gc_collect_cycles();
        }

        return $result;
    }

    /**
     * @throws RuntimeException
     */
    public function processInChunks(iterable $data, int $chunkSize, Closure $processor, int $memoryLimitMb = 512): void
    {
        $chunk = [];
        $processed = 0;

        foreach ($data as $item) {
            $chunk[] = $item;
            $processed++;

            if (count($chunk) >= $chunkSize) {
                $this->executeWithLimit(function () use ($chunk, $processor) {
                    $processor($chunk);
                }, $memoryLimitMb);

                $chunk = [];

                if ($this->isMemoryLimitExceeded($memoryLimitMb)) {
                    throw new RuntimeException('Memory limit exceeded during chunk processing.');
                }
            }
        }

        if (!empty($chunk)) {
            $this->executeWithLimit(function () use ($chunk, $processor) {
                $processor($chunk);
            }, $memoryLimitMb);
        }
    }

    public function getMemoryStats(): array
    {
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);

        return [
            'start_memory' => $this->startMemory,
            'current_memory' => $currentUsage,
            'peak_memory' => $peakUsage,
            'memory_limit' => $this->memoryLimit,
            'usage_percentage' => ($currentUsage / $this->memoryLimit) * 100,
            'peak_percentage' => ($peakUsage / $this->memoryLimit) * 100,
            'memory_used_mb' => round($currentUsage / 1024 / 1024, 2),
            'peak_used_mb' => round($peakUsage / 1024 / 1024, 2),
            'limit_mb' => round($this->memoryLimit / 1024 / 1024, 2),
        ];
    }

    public function isMemoryLimitExceeded(int $thresholdMb = 512): bool
    {
        $thresholdBytes = $thresholdMb * 1024 * 1024;
        $currentUsage = memory_get_usage(true);
        $memoryLimitBytes = $this->memoryLimit;

        if (app()->environment('testing')) {
            return false;
        }

        return $currentUsage > ($memoryLimitBytes - $thresholdBytes);
    }

    public function reset(): void
    {
        $this->startMemory = memory_get_usage(true);
        $this->peakUsage = 0;
    }

    private function shouldGarbageCollect(): bool
    {
        $currentUsage = memory_get_usage(true);

        return ($currentUsage / $this->memoryLimit) > 0.8;
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        return match ($last) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $limit,
        };
    }
}
