<?php

it('shows a not implemented error when trying to retry', function () {
    $this->artisan('ingest:retry', ['ingestRun' => 1])
        ->expectsOutputToContain('This feature is not yet implemented.')
        ->assertExitCode(1);
});