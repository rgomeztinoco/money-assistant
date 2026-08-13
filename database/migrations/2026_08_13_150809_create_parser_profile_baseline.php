<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('trusted_sender_address');
            $table->string('trusted_sender_domain');
            $table->string('authentication_mechanism', 8);
            $table->string('authenticated_domain');
            $table->timestampTz('enabled_at')->nullable()->index();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX parser_profiles_owner_name_unique ON parser_profiles (user_id, lower(name))');
        DB::statement("ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_authentication_mechanism_supported CHECK (authentication_mechanism IN ('spf', 'dkim', 'dmarc'))");
        DB::statement('ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_sender_address_lowercase CHECK (trusted_sender_address = lower(trusted_sender_address))');
        DB::statement('ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_sender_domain_lowercase CHECK (trusted_sender_domain = lower(trusted_sender_domain))');
        DB::statement('ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_authenticated_domain_lowercase CHECK (authenticated_domain = lower(authenticated_domain))');

        Schema::create('spending_notification_formats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parser_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime_source', 16);
            $table->char('rule_identifier', 64);
            $table->string('purpose', 16)->default('spending');
            $table->jsonb('definition');
            $table->timestampTz('enabled_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['parser_profile_id', 'rule_identifier'],
                'spending_notification_formats_rule_unique',
            );
        });

        DB::statement("ALTER TABLE spending_notification_formats ADD CONSTRAINT spending_notification_formats_mime_source_supported CHECK (mime_source IN ('text_plain', 'text_html'))");
        DB::statement("ALTER TABLE spending_notification_formats ADD CONSTRAINT spending_notification_formats_purpose_supported CHECK (purpose IN ('spending', 'ignore'))");
        DB::statement("ALTER TABLE spending_notification_formats ADD CONSTRAINT spending_notification_formats_definition_object CHECK (jsonb_typeof(definition) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_notification_formats');
        Schema::dropIfExists('parser_profiles');
    }
};
