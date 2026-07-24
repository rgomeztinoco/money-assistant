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
        Schema::create('suspected_duplicates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('first_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->foreignId('second_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('survivor_transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->foreignId('voided_transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique([
                'user_id',
                'first_transaction_id',
                'second_transaction_id',
            ]);
            $table->index(
                ['user_id', 'created_at', 'id'],
                'suspected_duplicates_unresolved_review_index',
            );
            $table->index('survivor_transaction_id');
        });

        DB::statement('ALTER TABLE suspected_duplicates ADD CONSTRAINT suspected_duplicates_canonical_pair CHECK (first_transaction_id < second_transaction_id)');
        DB::statement('ALTER TABLE suspected_duplicates ADD CONSTRAINT suspected_duplicates_revision_positive CHECK (revision > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE suspected_duplicates
            ADD CONSTRAINT suspected_duplicates_resolution_state_valid CHECK (
                (
                    survivor_transaction_id IS NULL
                    AND voided_transaction_id IS NULL
                    AND resolved_at IS NULL
                )
                OR (
                    survivor_transaction_id IN (first_transaction_id, second_transaction_id)
                    AND voided_transaction_id IN (first_transaction_id, second_transaction_id)
                    AND survivor_transaction_id <> voided_transaction_id
                    AND resolved_at IS NOT NULL
                )
            )
            SQL);
        DB::statement('DROP INDEX suspected_duplicates_unresolved_review_index');
        DB::statement('CREATE INDEX suspected_duplicates_unresolved_review_index ON suspected_duplicates (user_id, created_at DESC, id DESC) WHERE resolved_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX suspected_duplicates_resolved_voided_transaction_unique ON suspected_duplicates (voided_transaction_id) WHERE resolved_at IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspected_duplicates');
    }
};
