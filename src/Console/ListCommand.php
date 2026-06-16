<?php

namespace MadeByClowd\Sequenceable\Console;

use Illuminate\Console\Command;
use MadeByClowd\Sequenceable\Models\Sequence;

class ListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequence:list
                            {--module= : Filter by module name}
                            {--scope= : Filter by scope}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List active database sequence counters';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = config('sequenceable.connection');
        $query = Sequence::on($connectionName);

        if ($module = $this->option('module')) {
            $query->where('module', $module);
        }

        if ($scope = $this->option('scope')) {
            $query->where('scope', $scope);
        }

        $sequences = $query->get();

        if ($sequences->isEmpty()) {
            $this->components->info('No active sequence counters found.');

            return self::SUCCESS;
        }

        $headers = ['Module', 'Type Code', 'Period', 'Scope', 'Current Number', 'Template', 'Last Updated'];
        $rows = $sequences->map(function ($sequence) {
            return [
                $sequence->module,
                $sequence->type_code,
                $sequence->period,
                $sequence->scope,
                $sequence->current_number,
                $sequence->format_template ?? '(Default)',
                $sequence->updated_at?->toDateTimeString() ?? 'N/A',
            ];
        })->toArray();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
