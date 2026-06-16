---
name: laravel-sequenceable
description: "Use this skill for madebyclowd/laravel-sequenceable package in Laravel applications. ALWAYS use this skill when configuring or generating auto-incrementing document/record sequences, setting up multiple conditional sequence rules on models, adjusting concurrency database/Redis locks, setting up Hi/Lo pre-allocation caching, or running sequence artisan commands. Covers: HasSequenceNumber trait, Sequence facade, composite sequence key partitions, and sequence verification tools."
license: MIT
metadata:
  author: madebyclowd
---

# Laravel Sequenceable Development

## Quick Reference

### Installation and Setup

Run the setup wizard to publish configurations, publish database migrations, publish AI agent skills, and run migrations:
```bash
php artisan sequence:install
```

To manually republish assets or Laravel Boost skills:
```bash
php artisan vendor:publish --tag=sequenceable-config
php artisan vendor:publish --tag=sequenceable-migrations
php artisan vendor:publish --tag=sequenceable-boost-skills
```

### Basic Usage

To add a sequence to a model, implement the `Sequenceable` contract and use the `HasSequenceNumber` trait:

```php
use MadeByClowd\Sequenceable\Contracts\Sequenceable;
use MadeByClowd\Sequenceable\Traits\HasSequenceNumber;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    /**
     * Define sequence configs.
     */
    public function getSequenceConfig(): array
    {
        return [
            'number' => [
                'module' => 'invoice',
                'type_code' => 'INV',
                'period' => 'monthly', // daily, weekly, monthly, yearly, never (or custom date format/callable)
                'format_template' => '{type_code}-{YYYY}-{MM}-{seq:5}', // INV-2026-06-00001
                'pad_length' => 5,
            ]
        ];
    }
}
```

### Advanced Features

#### 1. Dynamic Type Code Resolution (`type_relation`)

If you want the sequence prefix to change based on a relationship (e.g. `$invoice->branch->code`):

```php
public function getSequenceConfig(): array
{
    return [
        'number' => [
            'module' => 'invoice',
            'type_relation' => 'branch', // Resolves relationship and uses the $branch->code attribute
            'default_type' => 'HQ',       // Fallback if relationship or code is missing
            'period' => 'yearly',
            'format_template' => '{type_code}-{YYYY}-{seq:6}',
        ]
    ];
}
```

If the relationship column is not named `code`, use the array syntax:
```php
'type_relation' => [
    'relation' => 'category',
    'column' => 'id_code',
]
```

#### 2. Multi-Tenant Scoping (`scope`)

Keep sequence pools strictly isolated across tenants or branches by specifying the `scope` configuration:

```php
public function getSequenceConfig(): array
{
    return [
        'number' => [
            'module' => 'invoice',
            'type_code' => 'INV',
            'scope' => 'tenant_id', // Resolves $model->tenant_id dynamically to scope the sequence pool
            'period' => 'monthly',
            'format_template' => '{type_code}-{YYYY}-{MM}-{seq:5}',
        ]
    ];
}
```

#### 3. Custom Reset Callables / Fiscal Years

For complex ERP requirements where sequences reset on custom dates (e.g. fiscal year starting April 1st):

```php
public function getSequenceConfig(): array
{
    return [
        'number' => [
            'module' => 'invoice',
            'type_code' => 'INV',
            'period' => function ($model) {
                // Return custom partition period string based on fiscal calendar
                $createdAt = $model->created_at ?? now();
                $year = $createdAt->month >= 4 ? $createdAt->year : $createdAt->year - 1;
                return "FY{$year}";
            },
            'format_template' => '{type_code}-{period}-{seq:5}',
        ]
    ];
}
```

#### 4. Multiple Sequences per Model

You can auto-generate sequences for multiple columns on the same model:

```php
public function getSequenceConfig(): array
{
    return [
        'invoice_number' => [
            'module' => 'invoice',
            'type_code' => 'INV',
            'format_template' => '{type_code}-{YYYY}-{seq:5}',
        ],
        'internal_ref' => [
            'module' => 'internal',
            'type_code' => 'REF',
            'period' => 'never',
            'format_template' => '{type_code}-{seq:8}',
        ]
    ];
}
```

#### 5. Flexible Custom Date Formats

While standard shorthand tokens (like `{YYYY}` or `{MM}`) are quick and easy for common templates, the `{date:FORMAT}` token gives you complete control using any standard PHP date formatting parameters (e.g., to output month names, day names, custom separators, etc.):

```php
public function getSequenceConfig(): array
{
    return [
        'number' => [
            'module' => 'invoice',
            'type_code' => 'INV',
            // Generates e.g., INV-16-Jun-2026-00001 (using 'd-M-Y' PHP date format)
            'format_template' => '{type_code}-{date:d-M-Y}-{seq:5}', 
        ]
    ];
}
```

#### 6. Advanced Enterprise Features

For enterprise numbering requirements, the following properties are supported directly within the model sequence configuration:

```php
public function getSequenceConfig(): array
{
    return [
        'number' => [
            'module' => 'invoice',
            'type_code' => 'INV',
            'format_template' => 'INV-{YYYY}-{seq:5}',
            
            // Starting value (default 1)
            'start_value' => 1000, 
            
            // Custom increment step (default 1)
            'step' => 2, 
            
            // Maximum sequence limit (throws SequenceExhaustedException if exceeded)
            'max_value' => 99999, 
            
            // Set to false to throw a validation exception on manual input overrides
            'allow_manual' => false, 
            
            // Enable continuous sequence recycling (deleted numbers are recycled and reused)
            'continuous' => true, 
            
            // Database connection override for this specific sequence
            'connection' => 'custom_connection', 
        ]
    ];
}
```

#### 7. Manual Sequence Facade Generation

To manually query, increment, or recycle sequences in service classes, jobs, or custom commands:

```php
use MadeByClowd\Sequenceable\Facades\Sequence;

// Fetch and increment next sequence value
$number = Sequence::generate(
    'order', 
    'SO', 
    '202606', 
    '{type_code}-{YYYY}-{seq:5}', 
    5, 
    'tenant_1',
    null,      // $model context
    null,      // $connection override
    1,         // $startValue
    1,         // $step
    false,     // $continuous
    99999      // $maxValue
);

// Manually recycle a number back into the continuous queue
Sequence::recycle('order', 'SO', '202606', 'tenant_1', 105);

// Get the current number without incrementing
$current = Sequence::getCurrent('order', 'SO', '202606', 'tenant_1');

// Reset or offset the sequence
Sequence::reset('order', 'SO', '202606', 'tenant_1', 100); // Next number will be 101
```

---

## Artisan Commands Checklist

1. **List Active Sequences**:
   ```bash
   php artisan sequence:list
   php artisan sequence:list --module=invoice
   ```
2. **Reset/Offset Counter**:
   ```bash
   php artisan sequence:reset invoice INV --value=500
   ```
3. **Verify and Repair Sequence Drift**:
   Compare the highest generated number in the actual model table with the database counter to verify synchronization:
   ```bash
   php artisan sequence:verify "App\Models\Invoice" number
   # To automatically repair drift by fast-forwarding the counter:
   php artisan sequence:verify "App\Models\Invoice" number --repair
   ```

---

## Common Pitfalls

- **Bypassing the Trait check**: Manually assigning the sequence column to a value before saving will trigger the **Manual Override Protection**, skipping generation. Make sure the column is empty/null if you want the package to generate the value.
- **Cache connection locks**: If using `'cache'` driver for locking, ensure your cache configuration (`config/cache.php`) supports locks (e.g. `redis`, `memcached`, `database` drivers. `file` and `array` do not support concurrent locks properly).
- **Composite keys in DB**: If raw querying sequences, remember that the table primary key is composite `['module', 'type_code', 'period', 'scope']`. Always run queries enlisting all four columns to guarantee locking performance and accuracy.
- **Hi/Lo Caching Reset**: If you manually alter a sequence counter directly in the database while `pre_allocation` is enabled, remember to clear the cached sequence pool using `Cache::forget('sequenceable_pool:module:type:period:scope')` or by running `php artisan sequence:reset`.
