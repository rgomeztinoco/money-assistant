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
        DB::statement(<<<'SQL'
            UPDATE spending_notification_references AS reference
            SET parser_profile_version_id = format.parser_profile_version_id
            FROM spending_notification_formats AS format
            WHERE reference.spending_notification_format_id = format.id
              AND reference.parser_profile_version_id IS NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE spending_notification_references AS reference
            SET gmail_message_discovery_id = discovery.id
            FROM gmail_message_discoveries AS discovery
            INNER JOIN gmail_connections AS connection
                ON connection.id = discovery.gmail_connection_id
            WHERE reference.gmail_message_discovery_id IS NULL
              AND reference.user_id = connection.user_id
              AND reference.gmail_account_identity = connection.gmail_account_identity
              AND reference.message_id = discovery.message_id
            SQL);

        DB::statement(<<<'SQL'
            UPDATE spending_notification_references
            SET last_attempted_at = COALESCE(updated_at, created_at)
            WHERE last_attempted_at IS NULL
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The following schema rollback removes the backfilled context columns.
    }
};
