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
        Schema::create('integration_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('integration', 32);
            $table->string('work_type', 64);
            $table->string('work_id', 128);
            $table->string('source_identity');
            $table->string('failure_kind', 32);
            $table->string('last_error_code', 64);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestampTz('first_failed_at');
            $table->timestampTz('last_failed_at');
            $table->timestampTz('visible_at');
            $table->timestampTz('retry_until');
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('parked_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('recovered_at')->nullable();
            $table->timestampTz('last_replayed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'integration', 'work_type', 'work_id'],
                'integration_incidents_work_unique',
            );
            $table->index(
                ['user_id', 'visible_at', 'acknowledged_at', 'recovered_at'],
                'integration_incidents_visibility_index',
            );
            $table->index(['integration', 'parked_at']);
        });

        DB::statement("ALTER TABLE integration_incidents ADD CONSTRAINT integration_incidents_integration_supported CHECK (integration IN ('gmail', 'ai', 'bcrp', 'openclaw'))");
        DB::statement("ALTER TABLE integration_incidents ADD CONSTRAINT integration_incidents_work_type_supported CHECK (work_type IN ('gmail_synchronization', 'gmail_message', 'ai_classification', 'daily_exchange_rate_seed', 'reminder_delivery'))");
        DB::statement("ALTER TABLE integration_incidents ADD CONSTRAINT integration_incidents_failure_kind_supported CHECK (failure_kind IN ('transient', 'authentication', 'authorization', 'schema', 'confirmation', 'concurrency', 'validation', 'deterministic'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_incidents');
    }
};
