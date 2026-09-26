<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_message_discoveries', function (Blueprint $table): void {
            $table->timestampTz('dismissed_at')->nullable();
            $table->index(['gmail_connection_id', 'dismissed_at', 'id']);
        });

        DB::table('spending_notification_references')
            ->whereNull('gmail_message_discovery_id')
            ->orderBy('id')
            ->chunkById(100, function ($references): void {
                foreach ($references as $reference) {
                    $connectionId = DB::table('gmail_connections')
                        ->where('user_id', $reference->user_id)
                        ->where('gmail_account_identity', $reference->gmail_account_identity)
                        ->value('id');

                    if ($connectionId === null) {
                        continue;
                    }

                    DB::table('gmail_message_discoveries')->insertOrIgnore([
                        'gmail_connection_id' => $connectionId,
                        'message_id' => $reference->message_id,
                        'processed_at' => $reference->last_attempted_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $discoveryId = DB::table('gmail_message_discoveries')
                        ->where('gmail_connection_id', $connectionId)
                        ->where('message_id', $reference->message_id)
                        ->value('id');

                    DB::table('spending_notification_references')
                        ->where('id', $reference->id)
                        ->update(['gmail_message_discovery_id' => $discoveryId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('gmail_message_discoveries', function (Blueprint $table): void {
            $table->dropIndex(['gmail_connection_id', 'dismissed_at', 'id']);
            $table->dropColumn('dismissed_at');
        });
    }
};
