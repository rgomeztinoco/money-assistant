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
        DB::table('integration_incidents')
            ->where('integration', 'bcrp')
            ->orWhere('work_type', 'daily_exchange_rate_seed')
            ->delete();
        DB::table('jobs')
            ->where('payload', 'like', '%SeedDailyExchangeRate%')
            ->delete();
        DB::table('failed_jobs')
            ->where('payload', 'like', '%SeedDailyExchangeRate%')
            ->delete();

        DB::statement('ALTER TABLE integration_incidents DROP CONSTRAINT IF EXISTS integration_incidents_integration_supported');
        DB::statement('ALTER TABLE integration_incidents DROP CONSTRAINT IF EXISTS integration_incidents_work_type_supported');
        DB::statement("ALTER TABLE integration_incidents ADD CONSTRAINT integration_incidents_integration_supported CHECK (integration IN ('gmail', 'openclaw'))");
        DB::statement("ALTER TABLE integration_incidents ADD CONSTRAINT integration_incidents_work_type_supported CHECK (work_type IN ('gmail_synchronization', 'gmail_message', 'reminder_delivery'))");

        Schema::dropIfExists('daily_exchange_rate_seed_requests');
        Schema::dropIfExists('daily_exchange_rates');

        if (Schema::hasColumn('users', 'reporting_currency')) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_reporting_currency_supported');

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('reporting_currency');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Removed exchange-rate infrastructure cannot be restored.');
    }
};
