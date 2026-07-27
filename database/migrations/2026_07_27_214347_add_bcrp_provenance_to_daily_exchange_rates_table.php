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
        Schema::table('daily_exchange_rates', function (Blueprint $table) {
            $table->string('source', 32)->nullable();
            $table->string('source_series', 32)->nullable();
            $table->date('source_observed_on')->nullable();
            $table->timestampTz('source_retrieved_at')->nullable();
            $table->string('source_value', 64)->nullable();
            $table->unsignedSmallInteger('source_precision')->nullable();
        });

        DB::statement("ALTER TABLE daily_exchange_rates ADD CONSTRAINT daily_exchange_rates_source_supported CHECK (source IS NULL OR source = 'bcrp_data')");
        DB::statement("ALTER TABLE daily_exchange_rates ADD CONSTRAINT daily_exchange_rates_source_series_supported CHECK (source_series IS NULL OR source_series = 'PD04638PD')");
        DB::statement('ALTER TABLE daily_exchange_rates ADD CONSTRAINT daily_exchange_rates_source_complete CHECK ((source IS NULL AND source_series IS NULL AND source_observed_on IS NULL AND source_retrieved_at IS NULL AND source_value IS NULL AND source_precision IS NULL) OR (source IS NOT NULL AND source_series IS NOT NULL AND source_observed_on IS NOT NULL AND source_retrieved_at IS NOT NULL AND source_value IS NOT NULL AND source_precision IS NOT NULL))');
        DB::statement('ALTER TABLE daily_exchange_rates ADD CONSTRAINT daily_exchange_rates_authority_exclusive CHECK ((owner_managed_at IS NOT NULL AND source IS NULL) OR (owner_managed_at IS NULL AND source IS NOT NULL))');
        DB::statement('ALTER TABLE daily_exchange_rates ADD CONSTRAINT daily_exchange_rates_source_date_valid CHECK (source_observed_on IS NULL OR source_observed_on <= applicable_on)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_exchange_rates', function (Blueprint $table) {
            $table->dropColumn([
                'source',
                'source_series',
                'source_observed_on',
                'source_retrieved_at',
                'source_value',
                'source_precision',
            ]);
        });
    }
};
