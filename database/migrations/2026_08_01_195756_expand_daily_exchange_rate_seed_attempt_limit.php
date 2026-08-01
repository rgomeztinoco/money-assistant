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
        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests DROP CONSTRAINT daily_exchange_rate_seed_requests_attempts_bounded');
        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests ADD CONSTRAINT daily_exchange_rate_seed_requests_attempts_bounded CHECK (attempt_count <= 32)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('daily_exchange_rate_seed_requests')
            ->where('attempt_count', '>', 8)
            ->exists()) {
            throw new RuntimeException(
                'Daily exchange rate seed attempts exceed the former limit; use a forward migration instead of truncating incident history.',
            );
        }

        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests DROP CONSTRAINT daily_exchange_rate_seed_requests_attempts_bounded');
        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests ADD CONSTRAINT daily_exchange_rate_seed_requests_attempts_bounded CHECK (attempt_count <= 8)');
    }
};
