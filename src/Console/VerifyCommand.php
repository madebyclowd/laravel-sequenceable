<?php

namespace MadeByClowd\Sequenceable\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Sequenceable\Contracts\Sequenceable;
use MadeByClowd\Sequenceable\Facades\Sequence;

class VerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequence:verify
                            {model : The fully qualified Eloquent model class to verify (e.g. "App\Models\Invoice")}
                            {column : The column containing the sequence number (e.g. "number")}
                            {--module= : The module name (defaults to model table)}
                            {--type=GEN : The type code (defaults to GEN)}
                            {--period= : The period to check (defaults to current Ym)}
                            {--scope=default : The scope to check}
                            {--repair : Automatically update database counter to match highest model number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify and repair database sequence counters against actual model records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $column = $this->argument('column');
        $repair = $this->option('repair');

        if (! class_exists($modelClass)) {
            $this->components->error("Model class '{$modelClass}' does not exist.");

            return self::FAILURE;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->components->error("Class '{$modelClass}' is not a valid Eloquent Model subclass.");

            return self::FAILURE;
        }

        if (! is_subclass_of($modelClass, Sequenceable::class)) {
            $this->components->error("Model class '{$modelClass}' must implement 'MadeByClowd\\Sequenceable\\Contracts\\Sequenceable' interface.");

            return self::FAILURE;
        }

        $model = new $modelClass;
        $tableName = $model->getTable();

        if (! Schema::hasColumn($tableName, $column)) {
            $this->components->error("Database table '{$tableName}' does not have a column named '{$column}'.");

            return self::FAILURE;
        }

        $module = $this->option('module') ?: $tableName;
        $type = $this->option('type');
        $period = $this->option('period') ?: now()->format('Ym');
        $scope = $this->option('scope');

        $this->components->info("Scanning '{$modelClass}' records for column '{$column}'...");

        // Fetch values of this column using a memory-safe query builder
        $query = $modelClass::query()
            ->whereNotNull($column)
            ->where($column, '<>', '');

        if ($type) {
            $query->where($column, 'like', "%{$type}%");
        }
        if ($this->option('period')) {
            $query->where($column, 'like', "%{$period}%");
        }

        // Extract numbers from the strings (looks for digits at the end of string or right before non-digits)
        $maxNumber = 0;
        $found = false;

        foreach ($query->lazy(1000) as $record) {
            $val = $record->{$column};
            if (preg_match('/(\d+)(?:\D*)$/', $val, $matches)) {
                $num = (int) $matches[1];
                if ($num > $maxNumber) {
                    $maxNumber = $num;
                }
                $found = true;
            }
        }

        if (! $found) {
            $this->components->info("No records found with sequence values in '{$modelClass}'.");

            return self::SUCCESS;
        }

        $currentDbNumber = Sequence::getCurrent($module, $type, $period, $scope);

        $this->info("Highest sequence number found in model records: {$maxNumber}");
        $this->info("Current sequence counter in database: {$currentDbNumber}");

        if ($maxNumber > $currentDbNumber) {
            $drift = $maxNumber - $currentDbNumber;
            $this->components->warn("Drift detected! Database counter is behind by {$drift} counts.");

            if ($repair) {
                Sequence::reset($module, $type, $period, $scope, $maxNumber);
                $this->components->info("Successfully repaired database sequence counter to {$maxNumber}.");
            } else {
                $this->components->warn("Run with '--repair' option to automatically align the database sequence counter.");
            }
        } else {
            $this->components->info('Sequence is verified and in sync!');
        }

        return self::SUCCESS;
    }
}
