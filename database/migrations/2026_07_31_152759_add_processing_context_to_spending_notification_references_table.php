<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('spending_notification_references', function (Blueprint $table) {
            $table->foreignId('gmail_message_discovery_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('parser_profile_version_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->timestampTz('last_attempted_at')->nullable();

            $table->index(
                ['parser_profile_version_id', 'processing_outcome', 'created_at'],
                'spending_notification_references_profile_health_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spending_notification_references', function (Blueprint $table) {
            $table->dropIndex('spending_notification_references_profile_health_index');
            $table->dropConstrainedForeignId('parser_profile_version_id');
            $table->dropConstrainedForeignId('gmail_message_discovery_id');
            $table->dropColumn(['attempt_count', 'last_attempted_at']);
        });
    }
};
