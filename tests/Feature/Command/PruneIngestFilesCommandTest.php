<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/ingest_test_' . uniqid('', true);

    if (!is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0777, true);
    }

    config(['filesystems.disks.ingest_test_local' => [
        'driver' => 'local',
        'root' => $this->tempDir,
    ]]);

    config(['ingest.disk' => 'ingest_test_local']);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

it('prunes files older than default 24 hours', function () {
    $oldFile = 'ingest-temp/old_file.csv';
    $newFile = 'ingest-temp/new_file.csv';

    mkdir($this->tempDir . '/ingest-temp', 0777, true);

    file_put_contents($this->tempDir . '/' . $oldFile, 'old content');
    file_put_contents($this->tempDir . '/' . $newFile, 'new content');

    touch($this->tempDir . '/' . $oldFile, time() - (25 * 3600));
    touch($this->tempDir . '/' . $newFile, time());

    $this->artisan('ingest:prune-files')
        ->expectsOutputToContain("Deleted 1 old ingest files from disk 'ingest_test_local'.")
        ->assertExitCode(0);

    expect(file_exists($this->tempDir . '/' . $oldFile))->toBeFalse()
        ->and(file_exists($this->tempDir . '/' . $newFile))->toBeTrue();
});

it('respects the hours option', function () {
    $file = 'ingest-temp/two_hours_old.csv';
    mkdir($this->tempDir . '/ingest-temp', 0777, true);
    file_put_contents($this->tempDir . '/' . $file, 'content');

    touch($this->tempDir . '/' . $file, time() - (2 * 3600));

    $this->artisan('ingest:prune-files', ['--hours' => '1'])
        ->expectsOutputToContain('Deleted 1 old ingest files')
        ->assertExitCode(0);

    expect(file_exists($this->tempDir . '/' . $file))->toBeFalse();
});

it('does not prune files if they are within the retention period', function () {
    $file = 'ingest-temp/recent.csv';
    mkdir($this->tempDir . '/ingest-temp', 0777, true);
    file_put_contents($this->tempDir . '/' . $file, 'content');

    touch($this->tempDir . '/' . $file, time() - (2 * 3600));

    $this->artisan('ingest:prune-files', ['--hours' => '5'])
        ->expectsOutputToContain('Deleted 0 old ingest files')
        ->assertExitCode(0);

    expect(file_exists($this->tempDir . '/' . $file))->toBeTrue();
});

it('cleans up files from both temp and uploads directories', function () {
    $tempFile = 'ingest-temp/temp_old.csv';
    $uploadFile = 'ingest-uploads/upload_old.csv';
    $newUpload = 'ingest-uploads/upload_new.csv';

    mkdir($this->tempDir . '/ingest-temp', 0777, true);
    mkdir($this->tempDir . '/ingest-uploads', 0777, true);

    file_put_contents($this->tempDir . '/' . $tempFile, 'content');
    file_put_contents($this->tempDir . '/' . $uploadFile, 'content');
    file_put_contents($this->tempDir . '/' . $newUpload, 'content');

    $oldTime = time() - (30 * 3600);
    touch($this->tempDir . '/' . $tempFile, $oldTime);
    touch($this->tempDir . '/' . $uploadFile, $oldTime);

    touch($this->tempDir . '/' . $newUpload, time());

    $this->artisan('ingest:prune-files')
        ->expectsOutputToContain('Deleted 2 old ingest files')
        ->assertExitCode(0);

    expect(file_exists($this->tempDir . '/' . $tempFile))->toBeFalse()
        ->and(file_exists($this->tempDir . '/' . $uploadFile))->toBeFalse()
        ->and(file_exists($this->tempDir . '/' . $newUpload))->toBeTrue();
});

it('removes empty subdirectories after file deletion', function () {
    $subDir = 'ingest-temp/subdir';
    $file = $subDir . '/old.csv';

    mkdir($this->tempDir . '/' . $subDir, 0777, true);
    file_put_contents($this->tempDir . '/' . $file, 'content');

    touch($this->tempDir . '/' . $file, time() - (25 * 3600));

    $this->artisan('ingest:prune-files');

    expect(file_exists($this->tempDir . '/' . $file))->toBeFalse()
        ->and(is_dir($this->tempDir . '/' . $subDir))->toBeFalse()
        ->and(is_dir($this->tempDir . '/ingest-temp'))->toBeTrue();

});
