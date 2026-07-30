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
        DB::statement('ALTER TABLE receipt_proposals DROP CONSTRAINT receipt_proposals_model_supported');
        DB::statement("ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_model_supported CHECK (model IN ('openai/gpt-5.6', 'openai/gpt-5.6-sol'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE receipt_proposals DROP CONSTRAINT receipt_proposals_model_supported');
        DB::statement("ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_model_supported CHECK (model = 'openai/gpt-5.6') NOT VALID");
    }
};
