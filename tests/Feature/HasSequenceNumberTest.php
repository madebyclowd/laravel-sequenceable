<?php

namespace MadeByClowd\Sequenceable\Tests\Feature;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Sequenceable\Contracts\Sequenceable;
use MadeByClowd\Sequenceable\Exceptions\SequenceableException;
use MadeByClowd\Sequenceable\Facades\Sequence;
use MadeByClowd\Sequenceable\Tests\TestCase;
use MadeByClowd\Sequenceable\Traits\HasSequenceNumber;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class HasSequenceNumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations for the package
        $this->artisan('migrate', ['--database' => 'testing'])->run();

        // Create test tables
        Schema::create('test_branches', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
        });

        Schema::create('test_invoices', function ($table) {
            $table->id();
            $table->string('number')->nullable();
            $table->string('reference')->nullable();
            $table->string('custom_ref')->nullable();
            $table->foreignId('branch_id')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_start_invoices', function ($table) {
            $table->id();
            $table->string('seq_start')->nullable();
            $table->timestamps();
        });

        Schema::create('test_step_invoices', function ($table) {
            $table->id();
            $table->string('seq_step')->nullable();
            $table->timestamps();
        });

        Schema::create('test_max_invoices', function ($table) {
            $table->id();
            $table->string('seq_max')->nullable();
            $table->timestamps();
        });

        Schema::create('test_no_manual_invoices', function ($table) {
            $table->id();
            $table->string('seq_no_manual')->nullable();
            $table->timestamps();
        });

        Schema::create('test_closure_invoices', function ($table) {
            $table->id();
            $table->string('seq_closure')->nullable();
            $table->timestamps();
        });

        Schema::create('test_relation_dot_invoices', function ($table) {
            $table->id();
            $table->string('seq_relation_dot')->nullable();
            $table->foreignId('branch_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_continuous_invoices', function ($table) {
            $table->id();
            $table->string('seq_continuous')->nullable();
            $table->timestamps();
        });
    }

    /** @test */
    public function test_it_generates_basic_sequence_number_on_creation()
    {
        $invoice1 = TestInvoice::create();
        $invoice2 = TestInvoice::create();

        $currentPeriod = now()->format('Ym');

        $this->assertEquals("INV-{$currentPeriod}-00001", $invoice1->number);
        $this->assertEquals("INV-{$currentPeriod}-00002", $invoice2->number);
    }

    /** @test */
    public function test_it_resolves_type_code_via_relationship()
    {
        $branch = TestBranch::create(['name' => 'Bali Branch', 'code' => 'DPS']);

        $invoice = TestInvoice::create(['branch_id' => $branch->id]);

        $currentYear = now()->format('Y');
        $this->assertEquals("DPS-{$currentYear}-001", $invoice->reference);
    }

    /** @test */
    public function test_it_uses_fallback_default_type_if_relationship_is_missing()
    {
        $invoice = TestInvoice::create();

        $currentYear = now()->format('Y');
        $this->assertEquals("HQ-{$currentYear}-001", $invoice->reference);
    }

    /** @test */
    public function test_it_scopes_sequences_independently()
    {
        $invoiceT1_1 = TestInvoice::create(['tenant_id' => 'tenant-1']);
        $invoiceT2_1 = TestInvoice::create(['tenant_id' => 'tenant-2']);
        $invoiceT1_2 = TestInvoice::create(['tenant_id' => 'tenant-1']);

        $currentPeriod = now()->format('Ym');

        $this->assertEquals("INV-{$currentPeriod}-00001", $invoiceT1_1->number);
        $this->assertEquals("INV-{$currentPeriod}-00001", $invoiceT2_1->number);
        $this->assertEquals("INV-{$currentPeriod}-00002", $invoiceT1_2->number);
    }

    /** @test */
    public function test_it_respects_manual_override_values()
    {
        $invoice = TestInvoice::create(['number' => 'MANUAL-123']);

        $this->assertEquals('MANUAL-123', $invoice->number);

        // Next auto-generated invoice should start back at 1 since we bypassed
        $invoiceNext = TestInvoice::create();
        $currentPeriod = now()->format('Ym');
        $this->assertEquals("INV-{$currentPeriod}-00001", $invoiceNext->number);
    }

    /** @test */
    public function test_it_supports_custom_reset_callables()
    {
        $invoice = TestInvoice::create();

        // Custom callable formats period as 'custom-prefix'
        $this->assertEquals('custom-prefix-001', $invoice->custom_ref);
    }

    /** @test */
    public function test_pre_allocation_cache_increments_atomically()
    {
        config(['sequenceable.pre_allocation.enabled' => true]);
        config(['sequenceable.pre_allocation.block_size' => 5]);

        $invoice1 = TestInvoice::create();
        $invoice2 = TestInvoice::create();
        $invoice3 = TestInvoice::create();

        $currentPeriod = now()->format('Ym');
        $this->assertEquals("INV-{$currentPeriod}-00001", $invoice1->number);
        $this->assertEquals("INV-{$currentPeriod}-00002", $invoice2->number);
        $this->assertEquals("INV-{$currentPeriod}-00003", $invoice3->number);

        // Database counter should be advanced by the block size (5)
        $dbVal = Sequence::getCurrent('invoice', 'INV', $currentPeriod);
        $this->assertEquals(5, $dbVal);
    }

    /** @test */
    public function test_artisan_list_and_reset_commands()
    {
        TestInvoice::create();

        // List Command
        $this->artisan('sequence:list')
            ->assertExitCode(0);

        $currentPeriod = now()->format('Ym');
        $dbValBefore = Sequence::getCurrent('invoice', 'INV', $currentPeriod);
        $this->assertEquals(1, $dbValBefore);

        // Reset Command
        $this->artisan('sequence:reset invoice INV --value=100')
            ->expectsConfirmation('Are you sure you want to reset the sequence [invoice][INV]['.$currentPeriod.'][default] to 100?', 'yes')
            ->assertExitCode(0);

        $dbValAfter = Sequence::getCurrent('invoice', 'INV', $currentPeriod);
        $this->assertEquals(100, $dbValAfter);

        $invoiceNew = TestInvoice::create();
        $this->assertEquals("INV-{$currentPeriod}-00101", $invoiceNew->number);
    }

    /** @test */
    public function test_artisan_verify_and_repair_command()
    {
        $currentPeriod = now()->format('Ym');

        // Create records
        TestInvoice::create(); // number is INV-202606-00001
        TestInvoice::create(); // number is INV-202606-00002

        // Manually decrease DB counter to simulate drift
        Sequence::reset('invoice', 'INV', $currentPeriod, 'default', 0);

        $this->artisan('sequence:verify', [
            'model' => TestInvoice::class,
            'column' => 'number',
            '--type' => 'INV',
            '--module' => 'invoice',
        ])
            ->expectsOutputToContain('Drift detected! Database counter is behind')
            ->assertExitCode(0);

        // Verify and Repair
        $this->artisan('sequence:verify', [
            'model' => TestInvoice::class,
            'column' => 'number',
            '--type' => 'INV',
            '--module' => 'invoice',
            '--repair' => true,
        ])
            ->expectsOutputToContain('Successfully repaired database sequence counter to 2')
            ->assertExitCode(0);

        // Database value should now be 2
        $this->assertEquals(2, Sequence::getCurrent('invoice', 'INV', $currentPeriod));
    }

    /** @test */
    public function test_it_automatically_publishes_boost_skills_when_boost_commands_run()
    {
        $targetSkillPath = base_path('.github/skills/laravel-sequenceable/SKILL.md');
        $boostJsonPath = base_path('boost.json');

        if (file_exists($targetSkillPath)) {
            unlink($targetSkillPath);
        }
        if (file_exists($boostJsonPath)) {
            unlink($boostJsonPath);
        }

        file_put_contents($boostJsonPath, json_encode([
            'skills' => ['laravel-best-practices'],
        ]));

        $this->assertFileDoesNotExist($targetSkillPath);

        Event::dispatch(
            new CommandFinished(
                'boost:install',
                new ArrayInput([]),
                new NullOutput,
                0
            )
        );

        $this->assertFileExists($targetSkillPath);

        $boostJson = json_decode(file_get_contents($boostJsonPath), true);
        $this->assertContains('laravel-sequenceable', $boostJson['skills']);

        // Cleanup
        if (file_exists($targetSkillPath)) {
            unlink($targetSkillPath);
            if (is_dir(dirname($targetSkillPath))) {
                rmdir(dirname($targetSkillPath));
            }
        }
        if (file_exists($boostJsonPath)) {
            unlink($boostJsonPath);
        }
    }

    /** @test */
    public function test_it_supports_custom_date_formats()
    {
        $invoice = TestInvoice::create();

        $formatted = Sequence::generate(
            'test_date',
            'DT',
            'global',
            'DT-{date:d-m-Y}-{seq:3}',
            3,
            'default',
            $invoice
        );

        $expectedDate = now()->format('d-m-Y');
        $this->assertEquals("DT-{$expectedDate}-001", $formatted);
    }

    /** @test */
    public function test_it_resolves_type_code_variations()
    {
        $invoice = TestInvoice::create();

        // 1. Using prefix before {type_code}
        $formatted1 = Sequence::generate('test_var', 'INV', 'global', 'BDG{type_code}-{YYYY}-{seq:2}', 2, 'default', $invoice);
        $currentYear = now()->format('Y');
        $this->assertEquals("BDGINV-{$currentYear}-01", $formatted1);

        // 2. Using alias {type-code}
        $formatted2 = Sequence::generate('test_var2', 'INV', 'global', 'BDG{type-code}-{YYYY}-{seq:2}', 2, 'default', $invoice);
        $this->assertEquals("BDGINV-{$currentYear}-01", $formatted2);

        // 3. Using alias {typeCode}
        $formatted3 = Sequence::generate('test_var3', 'INV', 'global', 'BDG{typeCode}-{YYYY}-{seq:2}', 2, 'default', $invoice);
        $this->assertEquals("BDGINV-{$currentYear}-01", $formatted3);
    }

    /** @test */
    public function test_it_supports_start_value()
    {
        $inv1 = TestStartInvoice::create();
        $this->assertEquals('ST-1000', $inv1->seq_start);

        $inv2 = TestStartInvoice::create();
        $this->assertEquals('ST-1001', $inv2->seq_start);
    }

    /** @test */
    public function test_it_supports_custom_step()
    {
        $inv1 = TestStepInvoice::create();
        $this->assertEquals('SP-1', $inv1->seq_step);

        $inv2 = TestStepInvoice::create();
        $this->assertEquals('SP-3', $inv2->seq_step);

        $inv3 = TestStepInvoice::create();
        $this->assertEquals('SP-5', $inv3->seq_step);
    }

    /** @test */
    public function test_it_enforces_max_value_limit()
    {
        TestMaxInvoice::create(); // mx-1
        TestMaxInvoice::create(); // mx-2
        TestMaxInvoice::create(); // mx-3

        $this->expectException(SequenceableException::class);
        $this->expectExceptionMessage('Sequence [adv_max][MX] has exceeded its maximum limit of 3');

        TestMaxInvoice::create(); // MX-4 should trigger exception
    }

    /** @test */
    public function test_it_enforces_manual_override_block()
    {
        $this->expectException(SequenceableException::class);
        $this->expectExceptionMessage("Manual assignment of sequence number on field 'seq_no_manual' is not allowed.");

        TestNoManualInvoice::create(['seq_no_manual' => 'MANUAL-123']);
    }

    /** @test */
    public function test_it_supports_closure_format_templates()
    {
        $inv1 = TestClosureInvoice::create();
        $this->assertEquals('CL-NEW-1', $inv1->seq_closure);
    }

    /** @test */
    public function test_it_resolves_nested_relation_dot_notation()
    {
        $branch = TestBranch::create(['name' => 'Tokyo Branch', 'code' => 'HND']);
        $inv = TestRelationDotInvoice::create(['branch_id' => $branch->id]);
        $this->assertEquals('RD-HND-1', $inv->seq_relation_dot);
    }

    /** @test */
    public function test_it_recycles_continuous_sequence_on_model_deletion()
    {
        $inv1 = TestContinuousInvoice::create(); // CN-1
        $inv2 = TestContinuousInvoice::create(); // CN-2
        $inv3 = TestContinuousInvoice::create(); // CN-3

        $this->assertEquals('CN-1', $inv1->seq_continuous);
        $this->assertEquals('CN-2', $inv2->seq_continuous);
        $this->assertEquals('CN-3', $inv3->seq_continuous);

        // Delete inv2 (CN-2)
        $inv2->delete();

        // The next created invoice should recycle CN-2
        $inv4 = TestContinuousInvoice::create();
        $this->assertEquals('CN-2', $inv4->seq_continuous);

        // The one after should be CN-4
        $inv5 = TestContinuousInvoice::create();
        $this->assertEquals('CN-4', $inv5->seq_continuous);
    }

    /** @test */
    public function test_it_resolves_isolated_connection_names_correctly()
    {
        config(['sequenceable.transaction_mode' => 'gap_tolerant']);
        config(['database.connections.mysql_test' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
        ]]);

        $resolved = Sequence::resolveConnectionName('mysql_test');
        $this->assertEquals('sequenceable_isolated_mysql_test', $resolved);
        $this->assertTrue(config()->has('database.connections.sequenceable_isolated_mysql_test'));
        $this->assertEquals('mysql', config('database.connections.sequenceable_isolated_mysql_test.driver'));
    }

    /** @test */
    public function test_it_validates_pad_length()
    {
        $this->expectException(SequenceableException::class);
        $this->expectExceptionMessage("Sequence config 'pad_length' must be a positive integer greater than 0.");

        Sequence::generate('order', 'SO', '202606', '{seq}', 0);
    }

    /** @test */
    public function test_it_validates_start_value()
    {
        $this->expectException(SequenceableException::class);
        $this->expectExceptionMessage("Sequence config 'start_value' must be greater than or equal to 0.");

        Sequence::generate('order', 'SO', '202606', '{seq}', 5, 'default', null, null, -1);
    }

    /** @test */
    public function test_it_validates_step()
    {
        $this->expectException(SequenceableException::class);
        $this->expectExceptionMessage("Sequence config 'step' must be a positive integer greater than 0.");

        Sequence::generate('order', 'SO', '202606', '{seq}', 5, 'default', null, null, 1, 0);
    }
}

// Test Models definition
class TestBranch extends Model
{
    protected $fillable = ['name', 'code'];

    protected $table = 'test_branches';
}

class TestInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['number', 'reference', 'custom_ref', 'branch_id', 'tenant_id'];

    protected $table = 'test_invoices';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(TestBranch::class);
    }

    public function getSequenceConfig(): array
    {
        return [
            'number' => [
                'module' => 'invoice',
                'type_code' => 'INV',
                'period' => 'monthly',
                'scope' => 'tenant_id',
                'format_template' => 'INV-{period}-{seq:5}',
                'pad_length' => 5,
            ],
            'reference' => [
                'module' => 'invoice_ref',
                'type_relation' => 'branch',
                'default_type' => 'HQ',
                'period' => 'yearly',
                'format_template' => '{type_code}-{YYYY}-{seq:3}',
                'pad_length' => 3,
            ],
            'custom_ref' => [
                'module' => 'invoice_custom',
                'type_code' => 'CUST',
                'period' => function ($model) {
                    return 'custom-prefix';
                },
                'format_template' => '{period}-{seq:3}',
                'pad_length' => 3,
            ],
        ];
    }
}

class TestStartInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['seq_start'];

    protected $table = 'test_start_invoices';

    public function getSequenceConfig(): array
    {
        return [
            'seq_start' => [
                'module' => 'adv_start',
                'type_code' => 'ST',
                'start_value' => 1000,
                'period' => 'never',
                'format_template' => 'ST-{seq}',
            ],
        ];
    }
}

class TestStepInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['seq_step'];

    protected $table = 'test_step_invoices';

    public function getSequenceConfig(): array
    {
        return [
            'seq_step' => [
                'module' => 'adv_step',
                'type_code' => 'SP',
                'step' => 2,
                'period' => 'never',
                'format_template' => 'SP-{seq}',
            ],
        ];
    }
}

class TestMaxInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['seq_max'];

    protected $table = 'test_max_invoices';

    public function getSequenceConfig(): array
    {
        return [
            'seq_max' => [
                'module' => 'adv_max',
                'type_code' => 'MX',
                'max_value' => 3,
                'period' => 'never',
                'format_template' => 'MX-{seq}',
            ],
        ];
    }
}

class TestNoManualInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['seq_no_manual'];

    protected $table = 'test_no_manual_invoices';

    public function getSequenceConfig(): array
    {
        return [
            'seq_no_manual' => [
                'module' => 'adv_no_manual',
                'type_code' => 'NM',
                'allow_manual' => false,
                'period' => 'never',
                'format_template' => 'NM-{seq}',
            ],
        ];
    }
}

class TestClosureInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['seq_closure'];

    protected $table = 'test_closure_invoices';

    public function getSequenceConfig(): array
    {
        return [
            'seq_closure' => [
                'module' => 'adv_closure',
                'type_code' => 'CL',
                'period' => 'never',
                'format_template' => function ($model) {
                    return 'CL-'.($model->id ? 'EXIST' : 'NEW').'-{seq}';
                },
            ],
        ];
    }
}

class TestRelationDotInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['seq_relation_dot', 'branch_id'];

    protected $table = 'test_relation_dot_invoices';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(TestBranch::class);
    }

    public function getSequenceConfig(): array
    {
        return [
            'seq_relation_dot' => [
                'module' => 'adv_relation_dot',
                'type_code' => 'RD',
                'period' => 'never',
                'format_template' => 'RD-{attribute:branch.code}-{seq}',
            ],
        ];
    }
}

class TestContinuousInvoice extends Model implements Sequenceable
{
    use HasSequenceNumber;

    protected $fillable = ['seq_continuous'];

    protected $table = 'test_continuous_invoices';

    public function getSequenceConfig(): array
    {
        return [
            'seq_continuous' => [
                'module' => 'adv_continuous',
                'type_code' => 'CN',
                'continuous' => true,
                'period' => 'never',
                'format_template' => 'CN-{seq}',
            ],
        ];
    }
}
