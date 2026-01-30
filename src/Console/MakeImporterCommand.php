<?php

declare(strict_types=1);

namespace LaravelIngest\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LaravelIngest\Enums\SourceType;
use RuntimeException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class MakeImporterCommand extends Command
{
    protected $signature = 'make:importer 
                            {name? : The name of the importer class}
                            {--model= : The model class to import into}
                            {--source= : The source type (upload, filesystem, url, ftp, sftp, json-stream)}';
    protected $description = 'Create a new importer class';

    public function __construct(
        protected Filesystem $files
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->argument('name') ?? text(
            label: 'What should the importer be named?',
            placeholder: 'ProductImporter',
            required: true,
            validate: fn(string $value) => preg_match('/^[A-Z][a-zA-Z0-9]*$/', $value)
                ? null
                : 'The name must be a valid class name starting with an uppercase letter.',
        );

        $model = $this->option('model') ?? text(
            label: 'Which model should this importer target?',
            placeholder: 'Product',
            required: true,
        );

        $sourceOption = $this->option('source');
        $validSources = array_map(static fn(SourceType $type) => $type->value, SourceType::cases());

        if ($sourceOption && !in_array($sourceOption, $validSources, true)) {
            $this->error("Invalid source type: {$sourceOption}");
            $this->line('Valid options: ' . implode(', ', $validSources));

            return self::FAILURE;
        }

        $source = $sourceOption ?? select(
            label: 'What source type should this importer use?',
            options: [
                'upload' => 'Upload - File uploaded via HTTP request',
                'filesystem' => 'Filesystem - File from local disk',
                'url' => 'URL - File fetched from remote URL',
                'ftp' => 'FTP - File from FTP server',
                'sftp' => 'SFTP - File from SFTP server',
                'json-stream' => 'JSON Stream - Streaming JSON parser',
            ],
            default: 'upload',
        );

        $className = Str::studly($name);
        $className = Str::endsWith($className, 'Importer') ? $className : $className . 'Importer';

        $modelClass = Str::studly($model);
        $sourceEnum = $this->getSourceEnumCase($source);

        $path = app_path("Importers/{$className}.php");

        if ($this->files->exists($path)) {
            $this->error("Importer already exists: {$path}");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($path));

        $stub = $this->getStub($className, $modelClass, $sourceEnum);
        $this->files->put($path, $stub);

        $this->components->info("Importer [{$path}] created successfully.");
        $this->newLine();
        $this->line("  Don't forget to register your importer in <comment>config/ingest.php</comment>:");
        $this->newLine();
        $this->line("  <comment>'importers' => [</comment>");
        $this->line("      <comment>'{$this->getImporterKey($className)}' => \\App\\Importers\\{$className}::class,</comment>");
        $this->line('  <comment>],</comment>');

        return self::SUCCESS;
    }

    protected function getSourceEnumCase(string $source): string
    {
        return match ($source) {
            'filesystem' => 'SourceType::FILESYSTEM',
            'url' => 'SourceType::URL',
            'ftp' => 'SourceType::FTP',
            'sftp' => 'SourceType::SFTP',
            'json-stream' => 'SourceType::JSON',
            default => 'SourceType::UPLOAD',
        };
    }

    protected function getImporterKey(string $className): string
    {
        $name = Str::replaceLast('Importer', '', $className);

        return Str::kebab($name);
    }

    protected function getStub(string $className, string $modelClass, string $sourceEnum): string
    {
        $stubPath = realpath(__DIR__ . '/stubs/importer.stub');

        if (!$this->files->exists($stubPath)) {
            throw new RuntimeException("Importer stub file not found at: {$stubPath}");
        }

        $stub = $this->files->get($stubPath);

        return str_replace([
            '{{ namespace }}',
            '{{ modelNamespace }}',
            '{{ modelClass }}',
            '{{ className }}',
            '{{ sourceEnum }}',
        ], [
            'App\\Importers',
            'App\\Models',
            $modelClass,
            $className,
            $sourceEnum,
        ], $stub);
    }
}
