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
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->string('category_assignment_provenance', 32)->nullable();
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_category_assignment_complete CHECK ((category_id IS NULL AND category_assignment_provenance IS NULL) OR (category_id IS NOT NULL AND category_assignment_provenance IN ('owner', 'linked_refund', 'learned_rule', 'ai')))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_assignment_complete');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('category_assignment_provenance');
        });
    }
};
