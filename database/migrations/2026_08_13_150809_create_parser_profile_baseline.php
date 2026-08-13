<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

            $table->unique(['user_id', 'name']);
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_notification_formats');
        Schema::dropIfExists('parser_profiles');
    }
};
