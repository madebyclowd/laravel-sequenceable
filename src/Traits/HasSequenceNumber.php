<?php

namespace MadeByClowd\Sequenceable\Traits;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Sequenceable\Contracts\Sequenceable;
use MadeByClowd\Sequenceable\Exceptions\SequenceableException;
use MadeByClowd\Sequenceable\Facades\Sequence;

trait HasSequenceNumber
{
    /**
     * Boot the trait and register the Eloquent creating event listener.
     */
    protected static function bootHasSequenceNumber(): void
    {
        static::creating(function (Model $model) {
            if (! $model instanceof Sequenceable) {
                return;
            }

            $rawConfigs = $model->getSequenceConfig();

            // Normalize config: support both single sequence config and multiple nested configs
            $configs = isset($rawConfigs['module']) || isset($rawConfigs['type_code']) || isset($rawConfigs['type_relation'])
                ? [($rawConfigs['column'] ?? 'number') => $rawConfigs]
                : $rawConfigs;

            foreach ($configs as $column => $config) {
                $columnName = is_numeric($column) ? ($config['column'] ?? 'number') : $column;

                // Manual input override protection: only generate if the attribute is empty
                if (empty($model->{$columnName})) {
                    $module = $config['module'] ?? $model->getTable();
                    $typeCode = $model->resolveSequenceTypeCode($config);
                    $period = $model->resolveSequencePeriod($config);
                    $scope = $model->resolveSequenceScope($config);
                    $formatTemplate = $config['format_template'] ?? null;
                    $padLength = (int) ($config['pad_length'] ?? 5);

                    $model->{$columnName} = Sequence::generate(
                        $module,
                        $typeCode,
                        $period,
                        $formatTemplate,
                        $padLength,
                        $scope,
                        $model,
                        $config['connection'] ?? null,
                        (int) ($config['start_value'] ?? 1),
                        (int) ($config['step'] ?? 1),
                        (bool) ($config['continuous'] ?? false),
                        isset($config['max_value']) ? (int) $config['max_value'] : null
                    );
                } else {
                    // Enforce manual override protection
                    $allowManual = (bool) ($config['allow_manual'] ?? true);
                    if (! $allowManual) {
                        throw new SequenceableException(
                            "Manual assignment of sequence number on field '{$columnName}' is not allowed."
                        );
                    }
                }
            }
        });

        static::deleted(function (Model $model) {
            if (! $model instanceof Sequenceable) {
                return;
            }

            $rawConfigs = $model->getSequenceConfig();
            $configs = isset($rawConfigs['module']) || isset($rawConfigs['type_code']) || isset($rawConfigs['type_relation'])
                ? [($rawConfigs['column'] ?? 'number') => $rawConfigs]
                : $rawConfigs;

            foreach ($configs as $column => $config) {
                $columnName = is_numeric($column) ? ($config['column'] ?? 'number') : $column;
                $continuous = (bool) ($config['continuous'] ?? false);

                if ($continuous && ! empty($model->{$columnName})) {
                    $val = $model->{$columnName};
                    if (preg_match('/(\d+)(?:\D*)$/', $val, $matches)) {
                        $number = (int) $matches[1];
                        $module = $config['module'] ?? $model->getTable();
                        $typeCode = $model->resolveSequenceTypeCode($config);
                        $period = $model->resolveSequencePeriod($config);
                        $scope = $model->resolveSequenceScope($config);
                        $connection = $config['connection'] ?? null;

                        Sequence::recycle($module, $typeCode, $period, $scope, $number, $connection);
                    }
                }
            }
        });
    }

    /**
     * Resolve the sequence type code based on configuration.
     */
    public function resolveSequenceTypeCode(array $config): string
    {
        if (isset($config['type_code'])) {
            return (string) $config['type_code'];
        }

        if (isset($config['type_relation'])) {
            $relationConfig = $config['type_relation'];

            if (is_array($relationConfig)) {
                $relationName = $relationConfig['relation'] ?? null;
                $column = $relationConfig['column'] ?? 'code';
            } else {
                $relationName = $relationConfig;
                $column = 'code';
            }

            if ($relationName && $relation = $this->{$relationName}) {
                return (string) ($relation->{$column} ?? ($config['default_type'] ?? 'UNK'));
            }

            return (string) ($config['default_type'] ?? 'UNK');
        }

        return (string) ($config['default_type'] ?? 'GEN');
    }

    /**
     * Resolve the partition period key based on reset configuration.
     */
    public function resolveSequencePeriod(array $config): string
    {
        $periodConfig = $config['period'] ?? 'monthly';
        $createdAt = $this->created_at ?? now();

        if ($periodConfig instanceof \Closure) {
            return (string) $periodConfig($this);
        }

        if (is_string($periodConfig) && class_exists($periodConfig)) {
            return (string) app($periodConfig)->resolve($this);
        }

        return match ($periodConfig) {
            'daily' => $createdAt->format('Ymd'),
            'weekly' => $createdAt->format('oW'),
            'monthly' => $createdAt->format('Ym'),
            'yearly' => $createdAt->format('Y'),
            'never', 'global' => 'global',
            default => is_string($periodConfig) ? $createdAt->format($periodConfig) : 'global',
        };
    }

    /**
     * Resolve the scope partition string based on configuration.
     */
    public function resolveSequenceScope(array $config): string
    {
        $scopeConfig = $config['scope'] ?? 'default';

        if ($scopeConfig instanceof \Closure) {
            return (string) $scopeConfig($this);
        }

        if (is_string($scopeConfig) && class_exists($scopeConfig)) {
            return (string) app($scopeConfig)->resolve($this);
        }

        if (is_string($scopeConfig) && $scopeConfig !== 'default') {
            // Check if it's an attribute on the model
            return (string) ($this->getAttribute($scopeConfig) ?? 'default');
        }

        return 'default';
    }
}
