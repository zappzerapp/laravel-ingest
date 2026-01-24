<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LaravelIngest\Services\PathSecurityService;

it('validates a safe path', function () {
    $service = new PathSecurityService();

    $result = $service->validatePath('ingest-uploads/file.csv');

    expect($result)->toBe('ingest-uploads/file.csv');
});

it('normalizes path separators', function () {
    $service = new PathSecurityService();

    $result = $service->validatePath('ingest-uploads\\subdir\\file.csv');

    expect($result)->toBe('ingest-uploads/subdir/file.csv');
});

it('removes null bytes', function () {
    $service = new PathSecurityService();

    $result = $service->validatePath('ingest-uploads/\0file.csv');

    expect($result)->toContain('ingest-uploads/');
});

it('throws exception for path traversal with ../', function () {
    $service = new PathSecurityService();

    expect(fn() => $service->validatePath('ingest-uploads/../../../etc/passwd'))
        ->toThrow(InvalidArgumentException::class, 'Path traversal detected');
});

it('throws exception for path traversal with backslash', function () {
    $service = new PathSecurityService();

    expect(fn() => $service->validatePath('ingest-uploads\..\windows\system32'))
        ->toThrow(InvalidArgumentException::class, 'Path traversal detected');
});

it('throws exception for encoded path traversal', function () {
    $service = new PathSecurityService();

    expect(fn() => $service->validatePath('ingest-uploads/%2e%2e%2fsecret'))
        ->toThrow(InvalidArgumentException::class, 'Path traversal detected');
});

it('throws exception for path outside allowed directories', function () {
    $service = new PathSecurityService();

    expect(fn() => $service->validatePath('other-dir/file.csv'))
        ->toThrow(InvalidArgumentException::class, 'allowed directories');
});

it('throws exception for empty path', function () {
    $service = new PathSecurityService();

    expect(fn() => $service->validatePath('/'))
        ->toThrow(InvalidArgumentException::class, 'Invalid path format');

    expect(fn() => $service->validatePath(''))
        ->toThrow(InvalidArgumentException::class, 'Invalid path format');
});

it('converts relative path to absolute path', function () {
    Storage::fake('local');
    $service = new PathSecurityService();

    Storage::disk('local')->makeDirectory('ingest-uploads');

    $path = 'ingest-uploads/test.csv';
    Storage::disk('local')->put($path, 'content');

    $absolutePath = $service->toAbsolutePath($path, 'local');

    $expectedRoot = realpath(Storage::disk('local')->path(''));
    expect($absolutePath)->toStartWith($expectedRoot);
    expect($absolutePath)->toEndWith('ingest-uploads/test.csv');
});

it('throws exception when absolute path resolves outside storage', function () {
    Storage::fake('local');
    $service = new PathSecurityService();

    expect(fn() => $service->toAbsolutePath('ingest-uploads/non-existent/file.csv', 'local'))
        ->toThrow(InvalidArgumentException::class, 'Resolved path is outside of storage directory');
});
