<?php

namespace MadeByClowd\Sequenceable\Console;

use Illuminate\Console\Command;
use MadeByClowd\Sequenceable\Facades\Sequence;

class ResetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequence:reset
                            {module : The module name (e.g. invoice)}
                            {type : The type code (e.g. INV)}
                            {--period= : Filter by period (defaults to current Ym)}
                            {--scope=default : Filter by scope}
                            {--value=0 : The value to reset to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset or offset a specific sequence counter';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $module = $this->argument('module');
        $type = $this->argument('type');
        $period = $this->option('period') ?: now()->format('Ym');
        $scope = $this->option('scope');
        $value = (int) $this->option('value');

        if (! $this->confirm("Are you sure you want to reset the sequence [{$module}][{$type}][{$period}][{$scope}] to {$value}?", true)) {
            $this->components->info('Reset cancelled.');

            return self::SUCCESS;
        }

        Sequence::reset($module, $type, $period, $scope, $value);

        $this->components->info("Sequence [{$module}][{$type}][{$period}][{$scope}] successfully reset to {$value}.");

        return self::SUCCESS;
    }
}
