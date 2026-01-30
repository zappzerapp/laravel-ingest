<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use LaravelIngest\Console\MakeImporterCommand;

beforeEach(function () {
    $stubPath = base_path('stubs/importer.stub');
    if (!file_exists(dirname($stubPath))) {
        mkdir(dirname($stubPath), 0755, true);
    }
    if (!file_exists($stubPath)) {
        file_put_contents($stubPath, <<<'STUB'
<?php

declare(strict_types=1);

namespace {{ namespace }};

use {{ modelNamespace }}\{{ modelClass }};
use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\IngestConfig;

class {{ className }} implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for({{ modelClass }}::class)
            ->fromSource({{ sourceEnum }})
            ->keyedBy('id') // The source column to identify unique records
            ->onDuplicate(DuplicateStrategy::SKIP)
            ->map([
                // 'source_column' => 'model_attribute',
            ]);
    }
}
STUB);
    }
});

afterEach(function () {
    $importersDir = app_path('Importers');
    if (is_dir($importersDir)) {
        $files = glob($importersDir . '/*.php');
        foreach ($files as $file) {
            unlink($file);
        }
        @rmdir($importersDir);
    }
});

describe('MakeImporterCommand', function () {
    describe('successful creation', function () {
        it('creates an importer with all options provided', function () {
            $this->artisan('make:importer', [
                'name' => 'Product',
                '--model' => 'Product',
                '--source' => 'upload',
            ])
                ->assertSuccessful()
                ->expectsOutputToContain('created successfully');

            expect(file_exists(app_path('Importers/ProductImporter.php')))->toBeTrue();

            $content = file_get_contents(app_path('Importers/ProductImporter.php'));
            expect($content)
                ->toContain('namespace App\Importers;')
                ->toContain('use App\Models\Product;')
                ->toContain('class ProductImporter implements IngestDefinition')
                ->toContain('SourceType::UPLOAD');
        });

        it('adds Importer suffix if not provided', function () {
            $this->artisan('make:importer', [
                'name' => 'User',
                '--model' => 'User',
                '--source' => 'upload',
            ])
                ->assertSuccessful();

            expect(file_exists(app_path('Importers/UserImporter.php')))->toBeTrue();

            $content = file_get_contents(app_path('Importers/UserImporter.php'));
            expect($content)->toContain('class UserImporter implements IngestDefinition');
        });

        it('does not duplicate Importer suffix if already provided', function () {
            $this->artisan('make:importer', [
                'name' => 'OrderImporter',
                '--model' => 'Order',
                '--source' => 'upload',
            ])
                ->assertSuccessful();

            expect(file_exists(app_path('Importers/OrderImporter.php')))->toBeTrue();

            $content = file_get_contents(app_path('Importers/OrderImporter.php'));
            expect($content)->toContain('class OrderImporter implements IngestDefinition');
        });

        it('normalizes class name to studly case', function () {
            $this->artisan('make:importer', [
                'name' => 'customer-order',
                '--model' => 'CustomerOrder',
                '--source' => 'upload',
            ])
                ->assertSuccessful();

            expect(file_exists(app_path('Importers/CustomerOrderImporter.php')))->toBeTrue();

            $content = file_get_contents(app_path('Importers/CustomerOrderImporter.php'));
            expect($content)->toContain('class CustomerOrderImporter implements IngestDefinition');
        });

        it('shows registration instructions after creation', function () {
            $this->artisan('make:importer', [
                'name' => 'Article',
                '--model' => 'Article',
                '--source' => 'upload',
            ])
                ->assertSuccessful()
                ->expectsOutputToContain("Don't forget to register")
                ->expectsOutputToContain('ArticleImporter');
        });
    });

    describe('source type mapping', function () {
        it('maps upload source type correctly', function () {
            $this->artisan('make:importer', [
                'name' => 'UploadTest',
                '--model' => 'Test',
                '--source' => 'upload',
            ])->assertSuccessful();

            $content = file_get_contents(app_path('Importers/UploadTestImporter.php'));
            expect($content)->toContain('SourceType::UPLOAD');
        });

        it('maps filesystem source type correctly', function () {
            $this->artisan('make:importer', [
                'name' => 'FilesystemTest',
                '--model' => 'Test',
                '--source' => 'filesystem',
            ])->assertSuccessful();

            $content = file_get_contents(app_path('Importers/FilesystemTestImporter.php'));
            expect($content)->toContain('SourceType::FILESYSTEM');
        });

        it('maps url source type correctly', function () {
            $this->artisan('make:importer', [
                'name' => 'UrlTest',
                '--model' => 'Test',
                '--source' => 'url',
            ])->assertSuccessful();

            $content = file_get_contents(app_path('Importers/UrlTestImporter.php'));
            expect($content)->toContain('SourceType::URL');
        });

        it('maps ftp source type correctly', function () {
            $this->artisan('make:importer', [
                'name' => 'FtpTest',
                '--model' => 'Test',
                '--source' => 'ftp',
            ])->assertSuccessful();

            $content = file_get_contents(app_path('Importers/FtpTestImporter.php'));
            expect($content)->toContain('SourceType::FTP');
        });

        it('maps sftp source type correctly', function () {
            $this->artisan('make:importer', [
                'name' => 'SftpTest',
                '--model' => 'Test',
                '--source' => 'sftp',
            ])->assertSuccessful();

            $content = file_get_contents(app_path('Importers/SftpTestImporter.php'));
            expect($content)->toContain('SourceType::SFTP');
        });

        it('maps json-stream source type correctly', function () {
            $this->artisan('make:importer', [
                'name' => 'JsonStreamTest',
                '--model' => 'Test',
                '--source' => 'json-stream',
            ])->assertSuccessful();

            $content = file_get_contents(app_path('Importers/JsonStreamTestImporter.php'));
            expect($content)->toContain('SourceType::JSON');
        });
    });

    describe('error handling', function () {
        it('validates class name during interactive prompt', function () {
            $this->artisan('make:importer')
                ->expectsQuestion('What should the importer be named?', 'invalidName')
                ->expectsOutputToContain('The name must be a valid class name starting with an uppercase letter.');
        });

        it('accepts valid class name during interactive prompt', function () {
            $this->artisan('make:importer', ['--model' => 'Product', '--source' => 'upload'])
                ->expectsQuestion('What should the importer be named?', 'ValidName')
                ->assertSuccessful()
                ->expectsOutputToContain('created successfully');

            expect(file_exists(app_path('Importers/ValidNameImporter.php')))->toBeTrue();
        });

        it('fails with invalid source type', function () {
            $this->artisan('make:importer', [
                'name' => 'Invalid',
                '--model' => 'Test',
                '--source' => 'invalid-source',
            ])
                ->assertFailed()
                ->expectsOutputToContain('Invalid source type: invalid-source')
                ->expectsOutputToContain('Valid options: upload, ftp, sftp, url, filesystem, json-stream');
        });

        it('fails when importer file already exists', function () {
            $this->artisan('make:importer', [
                'name' => 'Duplicate',
                '--model' => 'Test',
                '--source' => 'upload',
            ])->assertSuccessful();

            $this->artisan('make:importer', [
                'name' => 'Duplicate',
                '--model' => 'Test',
                '--source' => 'upload',
            ])
                ->assertFailed()
                ->expectsOutputToContain('Importer already exists:');
        });

        it('fails when stub file is missing', function () {
            $files = Mockery::mock(Filesystem::class);
            $files->shouldReceive('exists')
                ->with(app_path('Importers/MissingStubImporter.php'))
                ->andReturn(false);
            $files->shouldReceive('ensureDirectoryExists')->once();
            $files->shouldReceive('exists')
                ->with(base_path('stubs/importer.stub'))
                ->andReturn(false);

            $this->app->instance(Filesystem::class, $files);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Importer stub file not found at:');

            $this->artisan('make:importer', [
                'name' => 'MissingStub',
                '--model' => 'Test',
                '--source' => 'upload',
            ]);
        });
    });

    describe('importer key generation', function () {
        it('generates kebab-case key from class name', function () {
            $this->artisan('make:importer', [
                'name' => 'CustomerOrderImporter',
                '--model' => 'CustomerOrder',
                '--source' => 'upload',
            ])
                ->assertSuccessful()
                ->expectsOutputToContain('customer-order')
                ->expectsOutputToContain('CustomerOrderImporter');
        });

        it('removes Importer suffix before generating key', function () {
            $this->artisan('make:importer', [
                'name' => 'ProductImporter',
                '--model' => 'Product',
                '--source' => 'upload',
            ])
                ->assertSuccessful()
                ->expectsOutputToContain("'product'")
                ->expectsOutputToContain('ProductImporter');
        });

        it('handles multi-word class names', function () {
            $this->artisan('make:importer', [
                'name' => 'VeryLongClassNameImporter',
                '--model' => 'Test',
                '--source' => 'upload',
            ])
                ->assertSuccessful()
                ->expectsOutputToContain('very-long-class-name')
                ->expectsOutputToContain('VeryLongClassNameImporter');
        });
    });

    describe('stub content replacement', function () {
        it('replaces all placeholders correctly', function () {
            $this->artisan('make:importer', [
                'name' => 'Customer',
                '--model' => 'Customer',
                '--source' => 'filesystem',
            ])->assertSuccessful();

            $content = file_get_contents(app_path('Importers/CustomerImporter.php'));

            expect($content)
                ->toContain('namespace App\Importers;')
                ->toContain('use App\Models\Customer;')
                ->toContain('IngestConfig::for(Customer::class)')
                ->toContain('class CustomerImporter implements IngestDefinition')
                ->toContain('->fromSource(SourceType::FILESYSTEM)')
                ->not->toContain('{{ namespace }}')
                ->not->toContain('{{ modelNamespace }}')
                ->not->toContain('{{ modelClass }}')
                ->not->toContain('{{ className }}')
                ->not->toContain('{{ sourceEnum }}');
        });
    });
});

describe('MakeImporterCommand unit tests', function () {
    it('can be instantiated with filesystem dependency', function () {
        $files = new Filesystem();
        $command = new MakeImporterCommand($files);

        expect($command)->toBeInstanceOf(MakeImporterCommand::class);
    });
});
