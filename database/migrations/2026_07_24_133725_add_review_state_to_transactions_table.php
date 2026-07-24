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
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('provisional_fields')->default('[]');
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_revision_positive CHECK (revision > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE transactions
            ADD CONSTRAINT transactions_provisional_fields_reviewable
            CHECK (
                jsonb_typeof(provisional_fields) = 'array'
                AND provisional_fields <@ '["occurred_on", "amount_minor", "currency", "kind", "merchant_description"]'::jsonb
            )
            SQL);
        DB::statement('CREATE INDEX transactions_review_queue_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE jsonb_array_length(provisional_fields) > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX transactions_review_queue_index');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_revision_positive');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_provisional_fields_reviewable');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['revision', 'provisional_fields']);
        });
    }
};
