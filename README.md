# Laravel Sequenceable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-sequenceable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-sequenceable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-sequenceable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-sequenceable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

An enterprise-grade, highly customizable, and concurrency-safe number sequence auto-generator for Laravel Eloquent models (e.g., Invoices, Orders, CRM records, etc.).

---

## Why Choose Laravel Sequenceable?

Unlike other basic sequence generators on Packagist, this package is designed for enterprise systems:

*   **Concurrency Safe**: Employs pessimistic database locking (`SELECT ... FOR UPDATE`) or Redis-based distributed locks with automatic retry backoffs to prevent duplicate number generation under heavy load.
*   **Hi/Lo Pre-Allocation Caching**: Can allocate sequence numbers in blocks (e.g., 50 at a time) and increment them in-memory, eliminating database lock contention under extreme throughput.
*   **Composite Key Partitioning**: Segregates counters using composite primary keys `['module', 'type_code', 'period', 'scope']`. 
*   **Zero-Downtime Period Resets**: Automatically resets counters on date boundaries (daily, weekly, monthly, yearly) or custom fiscal periods without deleting historical counts.
*   **Dynamic Rules & Scopes**: Resolve type prefixes from model relations (e.g. `$invoice->branch->code`) and scope sequences by organizational units (e.g. `$invoice->tenant_id`).
*   **Flexible Format Placeholders**: Rich token parser supporting shorthands (`{YYYY}`, `{MM}`), custom PHP dates (`{date:d-M-Y}`), dynamic model attributes (`{attribute:customer_code}`), and random strings (`{rand:8}`).
*   **Verification & Repair Tooling**: Command-line tools to audit model records, detect counter drift, and repair sequence state in one click.

---

## Installation

Install the package via Composer:

```bash
composer require madebyclowd/laravel-sequenceable
```

Run the interactive installation wizard to publish the configuration file, database migrations, AI developer skills, and run the database migrations:

```bash
php artisan sequence:install
```

---

## Basic Usage

### 1. Implement and Configure Your Model

Add the `Sequenceable` contract and use the `HasSequenceNumber` trait on your Eloquent model:

```php
use MadeByClowd\Sequenceable\Contracts\Sequenceable;
use MadeByClowd\Sequenceable\Traits\HasSequenceNumber;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    /**
     * Return the sequence configurations for the model.
     */
    public function getSequenceConfig(): array
    {
        return [
            'number' => [
                'module' => 'invoice',
                'type_code' => 'INV',
                'period' => 'monthly', // daily, weekly, monthly, yearly, never (or custom date formats)
                'format_template' => '{type_code}-{YYYY}-{MM}-{seq:5}', // Outputs: INV-2026-06-00001
                'pad_length' => 5,
            ]
        ];
    }
}
```

### 2. Manual Override Protection
If you manually assign a value to the sequenced attribute before saving, the package will respect it and skip generation:

```php
$invoice = new Invoice();
$invoice->number = 'MANUAL-999';
$invoice->save(); // Bypasses sequence generator, keeping 'MANUAL-999'
```

---

## Advanced Usage

### 1. Dynamic Type Code Resolution (`type_relation`)
Resolve the type code prefix from a model relationship dynamically (e.g., Bali branch `DPS` vs Jakarta branch `JKT`):

```php
'number' => [
    'module' => 'invoice',
    'type_relation' => 'branch', // Calls $model->branch->code
    'default_type' => 'HQ',       // Fallback if relation is missing
    'period' => 'yearly',
    'format_template' => '{type_code}-{YYYY}-{seq:5}',
]
```

To use a relationship column other than `code`:
```php
'type_relation' => [
    'relation' => 'category',
    'column' => 'id_code',
]
```

### 2. Multi-Tenant Scoping (`scope`)
Isolate sequence pools across tenants or branches by specifying a scoping model attribute:

```php
'number' => [
    'module' => 'invoice',
    'type_code' => 'INV',
    'scope' => 'tenant_id', // Evaluates $model->tenant_id dynamically to separate counters
    'format_template' => '{type_code}-{YYYY}-{seq:5}',
]
```

### 3. Custom Reset Callables (Fiscal Calendar)
Provide a closure or a custom class string to partition sequences by custom dates (e.g., fiscal years starting in April):

```php
'number' => [
    'module' => 'invoice',
    'type_code' => 'INV',
    'period' => function ($model) {
        $createdAt = $model->created_at ?? now();
        $year = $createdAt->month >= 4 ? $createdAt->year : $createdAt->year - 1;
        return "FY{$year}";
    },
    'format_template' => '{type_code}-{period}-{seq:5}',
]
```

### 4. Custom PHP Date Formatting (`{date:FORMAT}`)
Format the template using standard PHP date character parameters:

```php
'format_template' => '{type_code}-{date:d-M-Y}-{seq:5}' // INV-16-Jun-2026-00001
```

### 5. Multi-Column Sequences
Generate sequences for multiple attributes on a single model:

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

### 6. Closure Format Templates & Nested Relation Placeholders
Customize your formatting templates dynamically using closures or fetch nested relation attributes using dot-notation:

```php
'number' => [
    'module' => 'invoice',
    'type_code' => 'INV',
    // 1. Fetching a nested relationship attribute:
    'format_template' => 'INV-{attribute:branch.company.code}-{seq:5}', 
    
    // 2. Closure-based template:
    'format_template' => function ($model) {
        return 'INV-' . ($model->is_priority ? 'URGENT' : 'NORMAL') . '-{seq:5}';
    }
]
```

### 7. Advanced Enterprise Features
For complex ERP/CRM numbering requirements, the following properties are supported directly within the model sequence configuration:

```php
'number' => [
    'module' => 'invoice',
    'type_code' => 'INV',
    'format_template' => '{type_code}-{seq:5}',
    
    // Set a custom starting value (defaults to 1)
    'start_value' => 1000, 
    
    // Set a custom increment step size (defaults to 1)
    'step' => 2, 
    
    // Set a maximum limit. Throws a SequenceExhaustedException if exceeded
    'max_value' => 99999, 
    
    // Enforce sequence integrity. Throws a SequenceableException if a manual value is set before saving
    'allow_manual' => false, 
    
    // Enable D365 continuous sequence (recycles deleted numbers automatically)
    'continuous' => true,
    
    // Database connection override for this specific sequence
    'connection' => 'tenant_db_connection', 
]
```

---

## Manual Generation (Facade)

Inject sequence values programmatically (e.g. in custom jobs, observers, or seeds):

```php
use MadeByClowd\Sequenceable\Facades\Sequence;

// Fetch and increment next sequence value (with optional connection, start value, step, continuous, max value)
$number = Sequence::generate(
    'order', 
    'SO', 
    '202606', 
    '{type_code}-{YYYY}-{seq:5}', 
    5, 
    'tenant_1',
    null,       // $model (optional)
    null,       // $connection (optional override)
    1,          // $startValue (optional, default 1)
    1,          // $step (optional, default 1)
    false,      // $continuous (optional, default false)
    99999       // $maxValue (optional, default null)
);

// Recycle a sequence number manually (inserts it back into sequence_recycled table)
Sequence::recycle('order', 'SO', '202606', 'tenant_1', 105);

// Get current value without incrementing
$current = Sequence::getCurrent('order', 'SO', '202606', 'tenant_1');

// Reset or offset the counter
Sequence::reset('order', 'SO', '202606', 'tenant_1', 100);
```

---

## Artisan Commands

### List Sequences
Display a table of all active sequence counters in the database:
```bash
php artisan sequence:list
php artisan sequence:list --module=invoice
```

### Reset Counters
Reset or set a specific sequence counter manually:
```bash
php artisan sequence:reset invoice INV --value=100
```

### Verify and Repair
Scan actual model tables for sequence column values, identify any counter drift, and optionally align the database sequence counters to prevent key collisions:
```bash
php artisan sequence:verify "App\Models\Invoice" number --type=INV --module=invoice
# To automatically repair:
php artisan sequence:verify "App\Models\Invoice" number --type=INV --module=invoice --repair
```

---

## Configuration (`config/sequenceable.php`)

Publishing configuration gives you full architectural control:

```php
return [
    'table' => 'sequences',
    'recycled_table' => 'sequence_recycled',
    'connection' => null,

    // Concurrency Locking Strategy
    'locking' => [
        'driver' => 'database',   // 'database' (Pessimistic lock), 'cache' (Atomic lock), or 'none'
        'cache_store' => null,    // cache connection name for atomic locks
        'timeout' => 5,           // seconds to block waiting for a lock
        'retry_interval' => 100,  // milliseconds between retry attempts
    ],

    // Transaction Mode:
    // 'gapless': increments within model transaction (rolls back on failure; no gaps)
    // 'gap_tolerant': increments in isolated transaction (commits immediately; minimizes lock duration)
    'transaction_mode' => 'gapless',

    // Hi/Lo Pre-Allocation Caching
    'pre_allocation' => [
        'enabled' => false,
        'block_size' => 50, // Grab 50 numbers at a time
    ],

    // Audit Tracking
    'audit' => [
        'enabled' => false, // Toggle created_by / updated_by tracking columns
        'user_model' => 'App\Models\User',
        'created_by_column' => 'created_by',
        'updated_by_column' => 'updated_by',
        'user_id_type' => 'bigInteger', // Options: 'bigInteger', 'uuid', 'ulid', 'string'
    ],
];
```

---

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
