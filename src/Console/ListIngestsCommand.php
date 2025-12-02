<?php

namespace LaravelIngest\Console;

use Illuminate\Console\Command;
use LaravelIngest\IngestManager;

class ListIngestsCommand extends Command
{
    protected $signature = 'ingest:list';
    protected $description = 'List all discoverable ingest definitions.';

    public function handle(IngestManager $ingestManager): int
    {
        $definitions = $ingestManager->getDefinitions();

        if (empty($definitions)) {
            $this->warn('No ingest definitions found.');
            $this->line('Ensure your ingest classes implement IngestDefinition and are tagged in a service provider.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d registered ingest definition(s):', count($definitions)));

        $rows = collect($definitions)->map(function ($definition, $slug) {
            $config = $definition->getConfig();
            return [
                'slug' => "<info>{$slug}</info>",
                'class' => get_class($definition),
                'model' => $config->model,
                'source' => $config->sourceType->value,
            ];
        });

        $this->table(['Slug', 'Class', 'Target Model', 'Source Type'], $rows);

        return self::SUCCESS;
    }
}