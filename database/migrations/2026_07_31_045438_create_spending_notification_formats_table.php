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
        Schema::create('spending_notification_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parser_profile_version_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime_source', 16);
            $table->char('rule_identifier', 64);
            $table->jsonb('definition');
            $table->timestamps();

            $table->unique(
                ['parser_profile_version_id', 'rule_identifier'],
                'spending_notification_formats_rule_unique',
            );
        });

        DB::statement("ALTER TABLE spending_notification_formats ADD CONSTRAINT spending_notification_formats_mime_source_supported CHECK (mime_source IN ('text_plain', 'text_html'))");
        DB::statement("ALTER TABLE spending_notification_formats ADD CONSTRAINT spending_notification_formats_definition_object CHECK (jsonb_typeof(definition) = 'object')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spending_notification_formats');
    }
};
