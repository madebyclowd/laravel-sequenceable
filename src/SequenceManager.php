<?php

namespace MadeByClowd\Sequenceable;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MadeByClowd\Sequenceable\Exceptions\SequenceLockException;
use MadeByClowd\Sequenceable\Models\Sequence;

class SequenceManager
{
    /**
     * Generate the next sequence number.
     *
     * @param  string  $module  Domain or entity area (e.g., 'invoice')
     * @param  string  $typeCode  Sub-type code (e.g., 'INV')
     * @param  string|null  $period  Reset period identifier (e.g., '202606' or resolved dynamically)
     * @param  mixed  $formatTemplate  The template format string or Closure
     * @param  int  $padLength  Zero padding length (default 5)
     * @param  string  $scope  Multi-tenancy or organizational scope (default 'default')
     * @param  Model|null  $model  Optional Eloquent model context for dynamic attributes
     * @param  string|null  $connection  Optional database connection override
     * @param  int  $startValue  Starting number value (default 1)
     * @param  int  $step  Increment step size (default 1)
     * @param  bool  $continuous  Whether to enable continuous sequence recycling
     * @param  int|null  $maxValue  Optional maximum limit
     */
    public function generate(
        string $module,
        string $typeCode,
        ?string $period = null,
        mixed $formatTemplate = null,
        int $padLength = 5,
        string $scope = 'default',
        ?Model $model = null,
        ?string $connection = null,
        int $startValue = 1,
        int $step = 1,
        bool $continuous = false,
        ?int $maxValue = null
    ): string {
        if ($padLength < 1) {
            throw new Exceptions\SequenceableException("Sequence config 'pad_length' must be a positive integer greater than 0.");
        }
        if ($startValue < 0) {
            throw new Exceptions\SequenceableException("Sequence config 'start_value' must be greater than or equal to 0.");
        }
        if ($step < 1) {
            throw new Exceptions\SequenceableException("Sequence config 'step' must be a positive integer greater than 0.");
        }

        $period = $period ?? now()->format('Ym');
        $resolvedConnection = $this->resolveConnectionName($connection);

        // Resolve format template if it's a closure
        if ($formatTemplate instanceof Closure) {
            $formatTemplate = $formatTemplate($model);
        }

        // 1. Continuous Sequence (Gap Recycling) Check
        if ($continuous) {
            $recycledNumber = $this->claimRecycledNumber($resolvedConnection, $module, $typeCode, $period, $scope);
            if ($recycledNumber !== null) {
                if ($maxValue !== null && $recycledNumber > $maxValue) {
                    throw new Exceptions\SequenceableException("Sequence [{$module}][{$typeCode}] has exceeded its maximum limit of {$maxValue}.");
                }

                return $this->formatNumber(
                    $module,
                    $typeCode,
                    $period,
                    $scope,
                    $recycledNumber,
                    $formatTemplate,
                    $padLength,
                    $model
                );
            }
        }

        // 2. Standard generation
        if (config('sequenceable.pre_allocation.enabled', false) && ! $continuous) {
            $nextNumber = $this->generateViaPreAllocation(
                $resolvedConnection,
                $module,
                $typeCode,
                $period,
                $scope,
                $formatTemplate,
                $startValue,
                $step
            );
        } else {
            $nextNumber = $this->generateViaLocking(
                $resolvedConnection,
                $module,
                $typeCode,
                $period,
                $scope,
                $formatTemplate,
                $startValue,
                $step
            );
        }

        if ($maxValue !== null && $nextNumber['number'] > $maxValue) {
            throw new Exceptions\SequenceableException("Sequence [{$module}][{$typeCode}] has exceeded its maximum limit of {$maxValue}.");
        }

        return $this->formatNumber(
            $module,
            $typeCode,
            $period,
            $scope,
            $nextNumber['number'],
            $nextNumber['template'],
            $padLength,
            $model
        );
    }

    /**
     * Get the current sequence counter value without incrementing.
     */
    public function getCurrent(string $module, string $typeCode, ?string $period = null, string $scope = 'default'): int
    {
        $period = $period ?? now()->format('Ym');
        $connectionName = $this->resolveConnectionName();

        $sequence = Sequence::on($connectionName)
            ->where('module', $module)
            ->where('type_code', $typeCode)
            ->where('period', $period)
            ->where('scope', $scope)
            ->first();

        return $sequence ? $sequence->current_number : 0;
    }

    /**
     * Reset or set the sequence counter to a specific number.
     */
    public function reset(
        string $module,
        string $typeCode,
        ?string $period = null,
        string $scope = 'default',
        int $resetTo = 0
    ): void {
        $period = $period ?? now()->format('Ym');
        $connectionName = $this->resolveConnectionName();
        $userId = Auth::id();

        // Clear pre-allocation cache if active
        if (config('sequenceable.pre_allocation.enabled', false)) {
            $cacheKey = $this->getPreAllocationCacheKey($module, $typeCode, $period, $scope);
            Cache::forget($cacheKey);
        }

        $auditEnabled = config('sequenceable.audit.enabled', false);
        $createdByColumn = config('sequenceable.audit.created_by_column', 'created_by');
        $updatedByColumn = config('sequenceable.audit.updated_by_column', 'updated_by');

        $attributes = [
            'current_number' => $resetTo,
        ];

        if ($auditEnabled && $userId) {
            $attributes[$updatedByColumn] = $userId;
        }

        $matchThese = [
            'module' => $module,
            'type_code' => $typeCode,
            'period' => $period,
            'scope' => $scope,
        ];

        $sequence = Sequence::on($connectionName)
            ->where('module', $module)
            ->where('type_code', $typeCode)
            ->where('period', $period)
            ->where('scope', $scope)
            ->first();

        if ($sequence) {
            $sequence->update(array_merge($attributes, $auditEnabled && $userId ? [$updatedByColumn => $userId] : []));
        } else {
            $attributes = array_merge($matchThese, $attributes, $auditEnabled && $userId ? [$createdByColumn => $userId, $updatedByColumn => $userId] : []);
            $sequence = new Sequence($attributes);
            $sequence->setConnection($connectionName);
            $sequence->save();
        }
    }

    /**
     * Generate next number using transactional concurrency locks.
     */
    protected function generateViaLocking(
        ?string $connectionName,
        string $module,
        string $typeCode,
        string $period,
        string $scope,
        ?string $formatTemplate,
        int $startValue = 1,
        int $step = 1
    ): array {
        $lockingDriver = config('sequenceable.locking.driver', 'database');
        $timeoutSeconds = config('sequenceable.locking.timeout', 5);

        if ($lockingDriver === 'cache') {
            $lockStore = config('sequenceable.locking.cache_store');
            $store = Cache::store($lockStore);
            if (! $store->getStore() instanceof LockProvider) {
                throw new Exceptions\SequenceableException(
                    "The cache store '".($lockStore ?: config('cache.default'))."' does not support atomic locks. Please configure a compatible store (e.g. redis, database, memcached)."
                );
            }

            $lockKey = "sequence_lock:{$module}:{$typeCode}:{$period}:{$scope}";
            $lock = $store->lock($lockKey, $timeoutSeconds);

            try {
                if (! $lock->block($timeoutSeconds)) {
                    throw SequenceLockException::lockAcquisitionFailed("{$module}:{$typeCode}", $timeoutSeconds);
                }

                return $this->incrementDatabaseSequence($connectionName, $module, $typeCode, $period, $scope, $formatTemplate, $step, $startValue, $step);
            } finally {
                $lock->release();
            }
        }

        // Database locking (Pessimistic) with retry loop
        $retryIntervalMs = config('sequenceable.locking.retry_interval', 100);
        $startTime = microtime(true);

        while (true) {
            try {
                return DB::connection($connectionName)->transaction(function () use ($connectionName, $module, $typeCode, $period, $scope, $formatTemplate, $step, $startValue) {
                    return $this->incrementDatabaseSequence($connectionName, $module, $typeCode, $period, $scope, $formatTemplate, $step, $startValue, $step);
                });
            } catch (\Throwable $e) {
                if ((microtime(true) - $startTime) >= $timeoutSeconds) {
                    throw SequenceLockException::lockAcquisitionFailed("{$module}:{$typeCode}", $timeoutSeconds);
                }
                usleep($retryIntervalMs * 1000);
            }
        }
    }

    /**
     * Generate next number using Hi/Lo pre-allocation caching.
     */
    protected function generateViaPreAllocation(
        ?string $connectionName,
        string $module,
        string $typeCode,
        string $period,
        string $scope,
        ?string $formatTemplate,
        int $startValue = 1,
        int $step = 1
    ): array {
        $cacheKey = $this->getPreAllocationCacheKey($module, $typeCode, $period, $scope);
        $blockSize = (int) config('sequenceable.pre_allocation.block_size', 50);
        $timeoutSeconds = config('sequenceable.locking.timeout', 5);

        // Fetch current block from cache
        $cached = Cache::get($cacheKey);

        if ($cached && ($cached['current'] + $step) <= $cached['max']) {
            $newCurrent = $cached['current'] + $step;
            $cached['current'] = $newCurrent;
            Cache::put($cacheKey, $cached, 86400); // Cache for 24h

            return [
                'number' => $newCurrent,
                'template' => $cached['template'],
            ];
        }

        // Cache empty or exhausted, fetch next block from database
        $lockStore = config('sequenceable.locking.cache_store');
        $store = Cache::store($lockStore);
        if (! $store->getStore() instanceof LockProvider) {
            throw new Exceptions\SequenceableException(
                "The cache store '".($lockStore ?: config('cache.default'))."' does not support atomic locks. Please configure a compatible store (e.g. redis, database, memcached)."
            );
        }

        $lockKey = "sequence_lock:pre_allocation:{$module}:{$typeCode}:{$period}:{$scope}";
        $lock = $store->lock($lockKey, $timeoutSeconds);

        try {
            if (! $lock->block($timeoutSeconds)) {
                throw SequenceLockException::lockAcquisitionFailed("{$module}:{$typeCode} (pre-allocation)", $timeoutSeconds);
            }

            // Double check cache after acquiring lock
            $cached = Cache::get($cacheKey);
            if ($cached && ($cached['current'] + $step) <= $cached['max']) {
                $newCurrent = $cached['current'] + $step;
                $cached['current'] = $newCurrent;
                Cache::put($cacheKey, $cached, 86400);

                return [
                    'number' => $newCurrent,
                    'template' => $cached['template'],
                ];
            }

            // Increment database by block size * step
            $incrementSize = $blockSize * $step;
            $dbResult = DB::connection($connectionName)->transaction(function () use ($connectionName, $module, $typeCode, $period, $scope, $formatTemplate, $incrementSize, $startValue, $step) {
                return $this->incrementDatabaseSequence($connectionName, $module, $typeCode, $period, $scope, $formatTemplate, $incrementSize, $startValue, $step);
            });

            $max = $dbResult['number'];
            $current = $max - $incrementSize + $step;

            Cache::put($cacheKey, [
                'current' => $current,
                'max' => $max,
                'template' => $dbResult['template'],
            ], 86400);

            return [
                'number' => $current,
                'template' => $dbResult['template'],
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Atomically fetch and increment the database sequence record.
     */
    protected function incrementDatabaseSequence(
        ?string $connectionName,
        string $module,
        string $typeCode,
        string $period,
        string $scope,
        ?string $formatTemplate,
        int $incrementBy,
        int $startValue = 1,
        int $step = 1
    ): array {
        $userId = Auth::id();
        $auditEnabled = config('sequenceable.audit.enabled', false);
        $createdByColumn = config('sequenceable.audit.created_by_column', 'created_by');
        $updatedByColumn = config('sequenceable.audit.updated_by_column', 'updated_by');

        $sequence = Sequence::on($connectionName)
            ->where('module', $module)
            ->where('type_code', $typeCode)
            ->where('period', $period)
            ->where('scope', $scope)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $attributes = [
                'module' => $module,
                'type_code' => $typeCode,
                'period' => $period,
                'scope' => $scope,
                'current_number' => $startValue + $incrementBy - $step,
                'format_template' => $formatTemplate,
            ];

            if ($auditEnabled && $userId) {
                $attributes[$createdByColumn] = $userId;
                $attributes[$updatedByColumn] = $userId;
            }

            $sequence = new Sequence($attributes);
            $sequence->setConnection($connectionName);
            $sequence->save();
        } else {
            // Update template if provided and different
            if ($formatTemplate && $sequence->format_template !== $formatTemplate) {
                $sequence->format_template = $formatTemplate;
            }

            $sequence->current_number += $incrementBy;

            if ($auditEnabled && $userId) {
                $sequence->setAttribute($updatedByColumn, $userId);
            }

            $sequence->save();
        }

        return [
            'number' => $sequence->current_number,
            'template' => $sequence->format_template,
        ];
    }

    /**
     * Format the sequence number based on template.
     */
    protected function formatNumber(
        string $module,
        string $typeCode,
        string $period,
        string $scope,
        int $number,
        ?string $template,
        int $padLength,
        ?Model $model
    ): string {
        $paddedNumber = str_pad((string) $number, $padLength, '0', STR_PAD_LEFT);

        if (! $template) {
            // Default fallback pattern: TYPE-PERIOD-PADDEDNUMBER
            return "{$typeCode}-{$period}-{$paddedNumber}";
        }

        // Replace custom date codes, padded numbers, and properties
        $replacements = [
            '{module}' => strtoupper($module),
            '{type_code}' => strtoupper($typeCode),
            '{type-code}' => strtoupper($typeCode),
            '{typeCode}' => strtoupper($typeCode),
            '{period}' => $period,
            '{scope}' => strtoupper($scope),
            '{number}' => $number,
            '{seq}' => $number,
            '{padded_number}' => $paddedNumber,
        ];

        // Format dates dynamically
        if ($model) {
            $createdAt = $model->created_at ?? now();
        } else {
            $createdAt = now();
        }

        $dateReplacements = [
            '{YYYY}' => $createdAt->format('Y'),
            '{YY}' => $createdAt->format('y'),
            '{MM}' => $createdAt->format('m'),
            '{M}' => $createdAt->format('n'),
            '{DD}' => $createdAt->format('d'),
            '{D}' => $createdAt->format('j'),
            '{HH}' => $createdAt->format('H'),
            '{mm}' => $createdAt->format('i'),
            '{ss}' => $createdAt->format('s'),
        ];

        $replacements = array_merge($replacements, $dateReplacements);

        $result = strtr($template, $replacements);

        // Replace custom date formats: {date:FORMAT}
        $result = preg_replace_callback('/\{date:([^{}]+)\}/', function ($matches) use ($createdAt) {
            return $createdAt->format($matches[1]);
        }, $result);

        // Replace dynamic length padded number: {seq:X} or {number:X}
        $result = preg_replace_callback('/\{(seq|number):(\d+)\}/', function ($matches) use ($number) {
            return str_pad((string) $number, (int) $matches[2], '0', STR_PAD_LEFT);
        }, $result);

        // Replace model attributes: {attribute:field} or {field:field} (supports dot notation)
        if ($model) {
            $result = preg_replace_callback('/\{(attribute|field):([a-zA-Z0-9_\.]+)\}/', function ($matches) use ($model) {
                return data_get($model, $matches[2]) ?? '';
            }, $result);
        }

        // Replace random strings: {rand:X} or {random:X}
        $result = preg_replace_callback('/\{(rand|random):(\d+)\}/', function ($matches) {
            return Str::random((int) $matches[2]);
        }, $result);

        return $result;
    }

    /**
     * Get pre-allocation cache key.
     */
    protected function getPreAllocationCacheKey(string $module, string $typeCode, string $period, string $scope): string
    {
        return "sequenceable_pool:{$module}:{$typeCode}:{$period}:{$scope}";
    }

    /**
     * Resolve the database connection name based on transaction mode and overrides.
     */
    public function resolveConnectionName(?string $connectionOverride = null): ?string
    {
        $connectionName = $connectionOverride ?? config('sequenceable.connection');

        if (config('sequenceable.transaction_mode', 'gapless') === 'gap_tolerant') {
            $baseConnection = $connectionName ?? config('database.default');
            $baseConfig = config("database.connections.{$baseConnection}");

            // Fallback for SQLite in-memory database
            if (isset($baseConfig['driver']) && $baseConfig['driver'] === 'sqlite' && ($baseConfig['database'] === ':memory:' || $baseConfig['database'] === '')) {
                return $connectionName;
            }

            $isolatedConnectionName = "sequenceable_isolated_{$baseConnection}";

            if (! config()->has("database.connections.{$isolatedConnectionName}")) {
                if ($baseConfig) {
                    config(["database.connections.{$isolatedConnectionName}" => $baseConfig]);
                }
            }

            return $isolatedConnectionName;
        }

        return $connectionName;
    }

    /**
     * Claim the oldest recycled number for a sequence partition.
     */
    protected function claimRecycledNumber(
        ?string $connectionName,
        string $module,
        string $typeCode,
        string $period,
        string $scope
    ): ?int {
        $recycledTable = config('sequenceable.recycled_table', 'sequence_recycled');

        return DB::connection($connectionName)->transaction(function () use ($connectionName, $recycledTable, $module, $typeCode, $period, $scope) {
            $record = DB::connection($connectionName)
                ->table($recycledTable)
                ->where('module', $module)
                ->where('type_code', $typeCode)
                ->where('period', $period)
                ->where('scope', $scope)
                ->orderBy('number', 'asc')
                ->lockForUpdate()
                ->first();

            if ($record) {
                DB::connection($connectionName)
                    ->table($recycledTable)
                    ->where('id', $record->id)
                    ->delete();

                return (int) $record->number;
            }

            return null;
        });
    }

    /**
     * Recycle a sequence number (inserts it back into sequence_recycled table).
     */
    public function recycle(
        string $module,
        string $typeCode,
        string $period,
        string $scope,
        int $number,
        ?string $connection = null
    ): void {
        $resolvedConnection = $this->resolveConnectionName($connection);
        $recycledTable = config('sequenceable.recycled_table', 'sequence_recycled');

        // Prevent duplicates in the recycled queue
        $exists = DB::connection($resolvedConnection)
            ->table($recycledTable)
            ->where('module', $module)
            ->where('type_code', $typeCode)
            ->where('period', $period)
            ->where('scope', $scope)
            ->where('number', $number)
            ->exists();

        if (! $exists) {
            DB::connection($resolvedConnection)
                ->table($recycledTable)
                ->insert([
                    'module' => $module,
                    'type_code' => $typeCode,
                    'period' => $period,
                    'scope' => $scope,
                    'number' => $number,
                    'created_at' => now(),
                ]);
        }
    }
}
