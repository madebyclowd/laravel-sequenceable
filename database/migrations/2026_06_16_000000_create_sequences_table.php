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
        $tableName = config('sequenceable.table', 'sequences');

        Schema::connection($this->getConnection())->create($tableName, function (Blueprint $table) {
            $table->string('module', 50);
            $table->string('type_code', 20);
            $table->string('period', 20);
            $table->string('scope', 50)->default('default');
            $table->bigInteger('current_number')->default(0);
            $table->string('format_template')->nullable();

            if (config('sequenceable.audit.enabled', false)) {
                $createdBy = config('sequenceable.audit.created_by_column', 'created_by');
                $updatedBy = config('sequenceable.audit.updated_by_column', 'updated_by');
                $idType = config('sequenceable.audit.user_id_type', 'bigInteger');

                switch ($idType) {
                    case 'uuid':
                        $table->uuid($createdBy)->nullable();
                        $table->uuid($updatedBy)->nullable();
                        break;
                    case 'ulid':
                        $table->ulid($createdBy)->nullable();
                        $table->ulid($updatedBy)->nullable();
                        break;
                    case 'string':
                        $table->string($createdBy)->nullable();
                        $table->string($updatedBy)->nullable();
                        break;
                    case 'bigInteger':
                    default:
                        $table->unsignedBigInteger($createdBy)->nullable();
                        $table->unsignedBigInteger($updatedBy)->nullable();
                        break;
                }
            }

            $table->timestamps();

            $table->primary(['module', 'type_code', 'period', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('sequenceable.table', 'sequences');

        Schema::connection($this->getConnection())->dropIfExists($tableName);
    }
};
