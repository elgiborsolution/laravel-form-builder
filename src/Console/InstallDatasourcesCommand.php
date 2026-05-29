<?php

namespace ESolution\DataSources\Console;

use ESolution\DataSources\Providers\DataSourcesServiceProvider;
use ESolution\DataSources\Support\AppRuntimeVariableRegistryStub;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class InstallDatasourcesCommand extends Command
{
    protected $signature = 'datasources:install {--publish-config : Also publish the package config file}';

    protected $description = 'Install the default runtime registry stub for the data sources package.';

    public function __construct(
        protected AppRuntimeVariableRegistryStub $stub
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('publish-config')) {
            $this->publishConfig();
        }

        $targetPath = app_path('Runtime/AppRuntimeVariableRegistry.php');

        if (File::exists($targetPath)) {
            $this->components->warn('AppRuntimeVariableRegistry already exists, skipping generation.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($targetPath));

        $written = File::put($targetPath, $this->stub->contents());

        if ($written === false) {
            $this->components->error('Unable to create AppRuntimeVariableRegistry.');

            return self::FAILURE;
        }

        $this->components->info("Created {$targetPath}");

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        Artisan::call('vendor:publish', [
            '--provider' => DataSourcesServiceProvider::class,
            '--tag' => 'datasources-config',
        ]);

        $this->output->write(Artisan::output());
    }
}
