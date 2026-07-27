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
        Schema::create('daily_exchange_rate_seed_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('applicable_on');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('missing_observation_count')->default(0);
            $table->unsignedSmallInteger('transport_failure_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('owner_entry_required_at')->nullable();
            $table->timestampTz('retrieval_failed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->foreignId('reminder_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('resolution_idempotency_key')->unique();
            $table->timestamps();

            $table->unique(['user_id', 'applicable_on']);
            $table->index(['completed_at', 'owner_entry_required_at', 'next_attempt_at']);
            $table->index('queued_at');
            $table->index('claimed_at');
        });

        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests ADD CONSTRAINT daily_exchange_rate_seed_requests_outcome_exclusive CHECK ((completed_at IS NULL OR (owner_entry_required_at IS NULL AND retrieval_failed_at IS NULL)) AND (owner_entry_required_at IS NULL OR retrieval_failed_at IS NULL))');
        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests ADD CONSTRAINT daily_exchange_rate_seed_requests_attempts_bounded CHECK (attempt_count <= 8)');
        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests ADD CONSTRAINT daily_exchange_rate_seed_requests_missing_observations_bounded CHECK (missing_observation_count <= 3)');
        DB::statement('ALTER TABLE daily_exchange_rate_seed_requests ADD CONSTRAINT daily_exchange_rate_seed_requests_transport_failures_bounded CHECK (transport_failure_count <= 5)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_exchange_rate_seed_requests');
    }
};
