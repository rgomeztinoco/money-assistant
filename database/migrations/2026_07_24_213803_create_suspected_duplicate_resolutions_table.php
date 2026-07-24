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
        Schema::create('suspected_duplicate_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suspected_duplicate_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->string('operation', 8);
            $table->foreignId('survivor_transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->unsignedInteger('expected_suspected_duplicate_revision');
            $table->unsignedInteger('expected_first_transaction_revision');
            $table->unsignedInteger('expected_second_transaction_revision');
            $table->string('expected_first_source_reference_fingerprint', 64)->nullable();
            $table->string('expected_second_source_reference_fingerprint', 64)->nullable();
            $table->unsignedInteger('result_suspected_duplicate_revision');
            $table->unsignedInteger('result_first_transaction_revision');
            $table->unsignedInteger('result_second_transaction_revision');
            $table->foreignId('result_survivor_transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->foreignId('result_voided_transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->timestamp('result_resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'idempotency_key'],
                'suspected_duplicate_resolutions_owner_idempotency_unique',
            );
            $table->unique([
                'suspected_duplicate_id',
                'result_suspected_duplicate_revision',
            ]);
        });

        Schema::create('suspected_duplicate_source_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suspected_duplicate_resolution_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('spending_notification_reference_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('from_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->foreignId('to_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique([
                'suspected_duplicate_resolution_id',
                'spending_notification_reference_id',
            ], 'suspected_duplicate_source_moves_resolution_reference_unique');
        });

        DB::statement("ALTER TABLE suspected_duplicate_resolutions ADD CONSTRAINT suspected_duplicate_resolutions_operation_supported CHECK (operation IN ('resolve', 'reopen'))");
        DB::statement('ALTER TABLE suspected_duplicate_resolutions ADD CONSTRAINT suspected_duplicate_resolutions_revision_sequence CHECK (expected_suspected_duplicate_revision > 0 AND result_suspected_duplicate_revision = expected_suspected_duplicate_revision + 1 AND expected_first_transaction_revision > 0 AND result_first_transaction_revision = expected_first_transaction_revision + 1 AND expected_second_transaction_revision > 0 AND result_second_transaction_revision = expected_second_transaction_revision + 1)');
        DB::statement(<<<'SQL'
            ALTER TABLE suspected_duplicate_resolutions
            ADD CONSTRAINT suspected_duplicate_resolutions_result_valid CHECK (
                (
                    operation = 'resolve'
                    AND survivor_transaction_id IS NOT NULL
                    AND expected_first_source_reference_fingerprint IS NOT NULL
                    AND expected_second_source_reference_fingerprint IS NOT NULL
                    AND result_survivor_transaction_id = survivor_transaction_id
                    AND result_voided_transaction_id IS NOT NULL
                    AND result_voided_transaction_id <> result_survivor_transaction_id
                    AND result_resolved_at IS NOT NULL
                )
                OR (
                    operation = 'reopen'
                    AND survivor_transaction_id IS NULL
                    AND expected_first_source_reference_fingerprint IS NULL
                    AND expected_second_source_reference_fingerprint IS NULL
                    AND result_survivor_transaction_id IS NULL
                    AND result_voided_transaction_id IS NULL
                    AND result_resolved_at IS NULL
                )
            )
            SQL);
        DB::statement('ALTER TABLE suspected_duplicate_source_moves ADD CONSTRAINT suspected_duplicate_source_moves_distinct_transactions CHECK (from_transaction_id <> to_transaction_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspected_duplicate_source_moves');
        Schema::dropIfExists('suspected_duplicate_resolutions');
    }
};
