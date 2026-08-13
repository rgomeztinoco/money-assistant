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
        Schema::table('parser_profiles', function (Blueprint $table) {
            $table->string('trusted_sender_address')->nullable();
            $table->string('trusted_sender_domain')->nullable();
            $table->string('authentication_mechanism', 8)->nullable();
            $table->string('authenticated_domain')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE parser_profiles AS profile
            SET trusted_sender_address = version.trusted_sender_address,
                trusted_sender_domain = version.trusted_sender_domain,
                authentication_mechanism = version.authentication_mechanism,
                authenticated_domain = version.authenticated_domain
            FROM parser_profile_versions AS version
            WHERE version.parser_profile_id = profile.id
              AND version.version = profile.current_version
            SQL);

        DB::statement('ALTER TABLE parser_profiles ALTER COLUMN trusted_sender_address SET NOT NULL');
        DB::statement('ALTER TABLE parser_profiles ALTER COLUMN trusted_sender_domain SET NOT NULL');
        DB::statement('ALTER TABLE parser_profiles ALTER COLUMN authentication_mechanism SET NOT NULL');
        DB::statement('ALTER TABLE parser_profiles ALTER COLUMN authenticated_domain SET NOT NULL');
        DB::statement("ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_authentication_mechanism_supported CHECK (authentication_mechanism IN ('spf', 'dkim', 'dmarc'))");
        DB::statement('ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_sender_address_lowercase CHECK (trusted_sender_address = lower(trusted_sender_address))');
        DB::statement('ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_sender_domain_lowercase CHECK (trusted_sender_domain = lower(trusted_sender_domain))');
        DB::statement('ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_authenticated_domain_lowercase CHECK (authenticated_domain = lower(authenticated_domain))');

        Schema::table('spending_notification_formats', function (Blueprint $table) {
            $table->foreignId('parser_profile_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->timestampTz('enabled_at')->nullable()->index();
        });

        DB::statement(<<<'SQL'
            UPDATE spending_notification_formats AS format
            SET parser_profile_id = version.parser_profile_id,
                enabled_at = profile.enabled_at
            FROM parser_profile_versions AS version
            INNER JOIN parser_profiles AS profile
                ON profile.id = version.parser_profile_id
               AND profile.current_version = version.version
            WHERE format.parser_profile_version_id = version.id
            SQL);

        DB::statement('DELETE FROM spending_notification_formats WHERE parser_profile_id IS NULL');
        DB::statement('ALTER TABLE spending_notification_formats ALTER COLUMN parser_profile_id SET NOT NULL');

        Schema::table('spending_notification_references', function (Blueprint $table) {
            $table->dropIndex('spending_notification_references_profile_health_index');
            $table->dropConstrainedForeignId('parser_profile_version_id');
        });

        Schema::table('spending_notification_formats', function (Blueprint $table) {
            $table->dropUnique('spending_notification_formats_rule_unique');
            $table->dropConstrainedForeignId('parser_profile_version_id');
            $table->unique(
                ['parser_profile_id', 'rule_identifier'],
                'spending_notification_formats_rule_unique',
            );
        });

        Schema::dropIfExists('parser_profile_versions');

        Schema::table('parser_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'current_version',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Parser Profile version history cannot be reconstructed after simplification.',
        );
    }
};
