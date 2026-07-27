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
        DB::table('category_assignments')
            ->where('source', 'owner')
            ->whereRaw('category_id IS DISTINCT FROM previous_category_id')
            ->update(['is_correction' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Correction classification is durable data; the schema rollback removes the column.
    }
};
