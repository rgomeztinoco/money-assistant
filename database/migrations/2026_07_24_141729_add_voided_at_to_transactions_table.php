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
            $table->timestamp('voided_at')->nullable();
        });

        DB::statement('DROP INDEX transactions_review_queue_index');
        DB::statement('CREATE INDEX transactions_active_ledger_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE voided_at IS NULL');
        DB::statement('CREATE INDEX transactions_voided_ledger_index ON transactions (user_id, voided_at DESC, id DESC) WHERE voided_at IS NOT NULL');
        DB::statement('CREATE INDEX transactions_review_queue_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE jsonb_array_length(provisional_fields) > 0 AND voided_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX transactions_review_queue_index');
        DB::statement('DROP INDEX transactions_active_ledger_index');
        DB::statement('DROP INDEX transactions_voided_ledger_index');
        DB::statement('CREATE INDEX transactions_review_queue_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE jsonb_array_length(provisional_fields) > 0');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('voided_at');
        });
    }
};
