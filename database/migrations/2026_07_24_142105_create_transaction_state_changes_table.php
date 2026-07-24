<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaction_state_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->string('operation', 8);
            $table->unsignedInteger('expected_revision');
            $table->unsignedInteger('result_revision');
            $table->timestamp('result_voided_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['transaction_id', 'result_revision']);
        });

        DB::statement("ALTER TABLE transaction_state_changes ADD CONSTRAINT transaction_state_changes_operation_supported CHECK (operation IN ('void', 'restore'))");
        DB::statement('ALTER TABLE transaction_state_changes ADD CONSTRAINT transaction_state_changes_revision_sequence CHECK (expected_revision > 0 AND result_revision = expected_revision + 1)');
        DB::statement("ALTER TABLE transaction_state_changes ADD CONSTRAINT transaction_state_changes_result_matches_operation CHECK ((operation = 'void' AND result_voided_at IS NOT NULL) OR (operation = 'restore' AND result_voided_at IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_state_changes');
    }
};
