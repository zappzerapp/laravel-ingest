<?php

namespace LaravelIngest\Console;

use Illuminate\Console\Command;
use LaravelIngest\Models\IngestRun;

class RetryIngestCommand extends Command
{
    protected $signature = 'ingest:retry {ingestRun : The ID of the ingest run to retry failed rows for}';
    protected $description = 'Create a new ingest run with only the failed rows from a previous run.';

    public function handle(): int
    {
        $this->error('This feature is not yet implemented.');
        // Zukünftige Logik:
        // 1. Finde den IngestRun.
        // 2. Prüfe, ob es fehlgeschlagene Zeilen gibt.
        // 3. Erstelle einen neuen IngestRun, der mit dem alten verknüpft ist.
        // 4. Lese alle IngestRow-Einträge mit Status 'failed'.
        // 5. Erstelle Chunks aus den `data`-Payloads dieser Zeilen.
        // 6. Starte einen neuen Batch mit diesen Chunks.
        return self::FAILURE;
    }
}