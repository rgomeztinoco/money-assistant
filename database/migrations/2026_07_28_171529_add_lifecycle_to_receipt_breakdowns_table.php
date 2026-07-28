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
        Schema::table('receipt_breakdowns', function (Blueprint $table) {
            $table->dropUnique(['transaction_id']);
            $table->foreignId('receipt_proposal_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->string('status', 16)->default('confirmed');
            $table->timestampTz('confirmed_at')->nullable();

            $table->unique('receipt_proposal_id');
        });

        DB::statement("ALTER TABLE receipt_breakdowns ADD CONSTRAINT receipt_breakdowns_status_supported CHECK (status IN ('draft', 'confirmed', 'superseded'))");
        DB::statement("CREATE UNIQUE INDEX receipt_breakdowns_one_draft_per_transaction ON receipt_breakdowns (transaction_id) WHERE status = 'draft'");
        DB::statement("CREATE UNIQUE INDEX receipt_breakdowns_one_confirmed_per_transaction ON receipt_breakdowns (transaction_id) WHERE status = 'confirmed'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipt_breakdowns', function (Blueprint $table) {
            DB::statement('DROP INDEX receipt_breakdowns_one_draft_per_transaction');
            DB::statement('DROP INDEX receipt_breakdowns_one_confirmed_per_transaction');
            $table->dropUnique(['receipt_proposal_id']);
            $table->dropConstrainedForeignId('receipt_proposal_id');
            $table->dropColumn(['status', 'confirmed_at']);
            $table->unique('transaction_id');
        });
    }
};
