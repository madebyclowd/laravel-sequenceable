<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the database connection for the migration.
     */
    public function getConnection(): ?string
    {
        return config('sequenceable.connection');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('sequenceable.recycled_table', 'sequence_recycled');

        Schema::connection($this->getConnection())->create($tableName, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('module', 50);
            $table->string('type_code', 20);
            $table->string('period', 20);
            $table->string('scope', 50)->default('default');
            $table->bigInteger('number');
            $table->timestamp('created_at')->useCurrent();

            // Composite index for ultra-fast lookup and sorting of recycled numbers
            $table->index(['module', 'type_code', 'period', 'scope', 'number'], 'seq_recycled_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('sequenceable.recycled_table', 'sequence_recycled');

        Schema::connection($this->getConnection())->dropIfExists($tableName);
    }
};
