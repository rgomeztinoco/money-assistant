<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gmail_account_identity');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestampTz('access_token_expires_at');
            $table->jsonb('granted_scopes');
            $table->timestampTz('connected_at');
            $table->timestampTz('last_successful_check_at');
            $table->timestampTz('last_check_failed_at')->nullable();
            $table->timestampTz('reauthorization_required_at')->nullable()->index();
            $table->string('last_error_code', 64)->nullable();
            $table->string('history_id')->nullable();
            $table->timestampTz('initial_sync_completed_at')->nullable();
            $table->timestampTz('last_successful_sync_at')->nullable();
            $table->timestampTz('last_synchronization_failed_at')->nullable()->index();
            $table->string('last_synchronization_error_code', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('gmail_message_discoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gmail_connection_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->timestampTz('processed_at')->nullable()->index();
            $table->timestampTz('processing_failed_at')->nullable()->index();
            $table->string('last_error_code', 64)->nullable();
            $table->uuid('failed_job_uuid')->nullable()->unique();
            $table->timestamps();

            $table->unique([
                'gmail_connection_id',
                'message_id',
            ], 'gmail_message_discoveries_identity_unique');
        });

        Schema::create('spending_notification_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('spending_notification_format_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gmail_message_discovery_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('gmail_account_identity');
            $table->string('message_id');
            $table->string('processing_outcome', 32);
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestamps();

            $table->unique([
                'user_id',
                'gmail_account_identity',
                'message_id',
            ], 'spending_notification_references_source_identity_unique');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_notification_references');
        Schema::dropIfExists('gmail_message_discoveries');
        Schema::dropIfExists('gmail_connections');
    }
};
