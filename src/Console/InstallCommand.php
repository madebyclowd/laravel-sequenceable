<?php

namespace MadeByClowd\AutoSequence\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequence:install
                            {--publish-config : Automatically publish configuration file}
                            {--publish-migrations : Automatically publish migrations files}
                            {--publish-skills : Automatically publish AI Agent skills}
                            {--migrate : Automatically run database migrations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up the Laravel Auto Sequence package (publish assets and migrate)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Setting up Laravel Auto Sequence package...');

        $hasExplicitOptions = $this->option('publish-config') ||
            $this->option('publish-migrations') ||
            $this->option('publish-skills') ||
            $this->option('migrate');

        // 1. Publish Config
        $publishConfig = $this->option('publish-config') || (! $hasExplicitOptions && $this->confirm('Do you want to publish the package configuration file?', true));
        if ($publishConfig) {
            $exit = $this->call('vendor:publish', [
                '--tag' => 'auto-sequence-config',
            ]);
            if ($exit !== self::SUCCESS) {
                $this->components->error('Failed to publish configuration file.');

                return self::FAILURE;
            }
            $this->components->info('Configuration file published.');
        }

        // 2. Publish Migrations
        $publishMigrations = $this->option('publish-migrations') || (! $hasExplicitOptions && $this->confirm('Do you want to publish the package migrations?', false));
        if ($publishMigrations) {
            $exit = $this->call('vendor:publish', [
                '--tag' => 'auto-sequence-migrations',
            ]);
            if ($exit !== self::SUCCESS) {
                $this->components->error('Failed to publish migrations.');

                return self::FAILURE;
            }
            $this->components->info('Migrations published.');
        }

        // 3. Publish AI Agent Skills
        $publishSkills = $this->option('publish-skills') || (! $hasExplicitOptions && $this->confirm('Do you want to publish Auto Sequence AI Agent skills for your workspace?', true));
        if ($publishSkills) {
            $exit = $this->call('vendor:publish', [
                '--tag' => 'auto-sequence-boost-skills',
            ]);
            if ($exit !== self::SUCCESS) {
                $this->components->error('Failed to publish AI agent skills.');

                return self::FAILURE;
            }
            $this->components->info('AI Agent skills published.');
        }

        // 4. Run Migrations
        $runMigrations = $this->option('migrate') || (! $hasExplicitOptions && $this->confirm('Do you want to run the database migrations?', true));
        if ($runMigrations) {
            $exit = $this->call('migrate');
            if ($exit !== self::SUCCESS) {
                $this->components->error('Database migrations failed.');

                return self::FAILURE;
            }
            $this->components->info('Database migrations completed.');
        }

        $this->components->info('Laravel Auto Sequence package setup finished!');

        return self::SUCCESS;
    }
}
