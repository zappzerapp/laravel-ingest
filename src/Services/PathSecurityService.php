<?php

declare(strict_types=1);

namespace LaravelIngest\Services;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PathSecurityService
{
    /**
     * @throws InvalidArgumentException
     */
    public function validatePath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);

        $normalizedPath = str_replace("\0", '', $normalizedPath);

        if ($this->hasPathTraversal($normalizedPath)) {
            throw new InvalidArgumentException('Path traversal detected in file path.');
        }

        $this->ensureWithinAllowedDirectories($normalizedPath);

        return $normalizedPath;
    }

    public function toAbsolutePath(string $path, string $disk = 'local'): string
    {
        $this->validatePath($path);

        $storagePath = Storage::disk($disk)->path('');
        $fullPath = rtrim($storagePath, '/') . '/' . ltrim($path, '/');

        $realStoragePath = realpath($storagePath);
        $realFullPath = realpath(dirname($fullPath)) . '/' . basename($fullPath);

        if ($realStoragePath === false || !str_starts_with($realFullPath, $realStoragePath)) {
            throw new InvalidArgumentException('Resolved path is outside of storage directory.');
        }

        return $fullPath;
    }

    private function hasPathTraversal(string $path): bool
    {
        $traversalPatterns = [
            '../',
            '..\\',
            '%2e%2e%2f',
            '%2e%2e%5c',
            '..%2f',
            '..%5c',
        ];

        foreach ($traversalPatterns as $pattern) {
            if (stripos($path, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function ensureWithinAllowedDirectories(string $path): void
    {
        $allowedDirectories = config('ingest_security.allowed_directories', [
            'ingest-uploads',
            'ingest-temp',
            'data',
            'imports',
        ]);

        $trimmedPath = ltrim($path, '/');

        if ($trimmedPath === '') {
            throw new InvalidArgumentException('Invalid path format.');
        }

        $pathParts = explode('/', $trimmedPath);
        $firstDirectory = $pathParts[0] ?? '';

        if (!in_array($firstDirectory, $allowedDirectories, true)) {
            throw new InvalidArgumentException(
                'Path must start with one of the allowed directories: ' . implode(', ', $allowedDirectories)
            );
        }
    }
}
