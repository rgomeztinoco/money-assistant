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
        Schema::create('parser_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parser_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('trusted_sender_address');
            $table->string('trusted_sender_domain');
            $table->string('authentication_mechanism', 8);
            $table->string('authenticated_domain');
            $table->string('source_gmail_account_identity');
            $table->string('source_message_id');
            $table->timestampTz('approved_at');
            $table->timestamps();

            $table->unique(
                ['parser_profile_id', 'version'],
                'parser_profile_versions_profile_version_unique',
            );
        });

        DB::statement("ALTER TABLE parser_profile_versions ADD CONSTRAINT parser_profile_versions_authentication_mechanism_supported CHECK (authentication_mechanism IN ('spf', 'dkim', 'dmarc'))");
        DB::statement('ALTER TABLE parser_profile_versions ADD CONSTRAINT parser_profile_versions_sender_address_lowercase CHECK (trusted_sender_address = lower(trusted_sender_address))');
        DB::statement('ALTER TABLE parser_profile_versions ADD CONSTRAINT parser_profile_versions_sender_domain_lowercase CHECK (trusted_sender_domain = lower(trusted_sender_domain))');
        DB::statement('ALTER TABLE parser_profile_versions ADD CONSTRAINT parser_profile_versions_authenticated_domain_lowercase CHECK (authenticated_domain = lower(authenticated_domain))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_profile_versions');
    }
};
