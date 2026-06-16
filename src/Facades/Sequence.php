<?php

namespace MadeByClowd\Sequenceable\Facades;

use Illuminate\Support\Facades\Facade;
use MadeByClowd\Sequenceable\SequenceManager;

/**
 * @method static string generate(string $module, string $typeCode, ?string $period = null, mixed $formatTemplate = null, int $padLength = 5, string $scope = 'default', ?\Illuminate\Database\Eloquent\Model $model = null, ?string $connection = null, int $startValue = 1, int $step = 1, bool $continuous = false, ?int $maxValue = null)
 * @method static int getCurrent(string $module, string $typeCode, ?string $period = null, string $scope = 'default')
 * @method static void reset(string $module, string $typeCode, ?string $period = null, string $scope = 'default', int $resetTo = 0)
 * @method static void recycle(string $module, string $typeCode, string $period, string $scope, int $number, ?string $connection = null)
 *
 * @see SequenceManager
 */
class Sequence extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'sequenceable';
    }
}
