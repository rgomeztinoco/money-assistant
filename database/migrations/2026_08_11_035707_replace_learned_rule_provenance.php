<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('transactions')
            ->where('category_assignment_provenance', 'learned_rule')
            ->update(['category_assignment_provenance' => 'merchant_rule']);
        DB::table('category_assignments')
            ->where('source', 'learned_rule')
            ->update(['source' => 'merchant_rule']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('transactions')
            ->where('category_assignment_provenance', 'merchant_rule')
            ->update(['category_assignment_provenance' => 'learned_rule']);
        DB::table('category_assignments')
            ->where('source', 'merchant_rule')
            ->update(['source' => 'learned_rule']);
    }
};
