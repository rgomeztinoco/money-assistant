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
        DB::statement('ALTER TABLE open_claw_pending_operations ALTER COLUMN effect_summary TYPE TEXT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('open_claw_pending_operations')
            ->whereRaw('char_length(effect_summary) > 512')
            ->exists()) {
            throw new RuntimeException(
                'OpenClaw effect summaries exceed the former limit; use a forward migration instead of truncating confirmed operation context.',
            );
        }

        DB::statement('ALTER TABLE open_claw_pending_operations ALTER COLUMN effect_summary TYPE VARCHAR(512)');
    }
};
