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
        Schema::table('spending_notification_formats', function (Blueprint $table) {
            $table->string('purpose', 16)->default('spending');
        });

        DB::statement("ALTER TABLE spending_notification_formats ADD CONSTRAINT spending_notification_formats_purpose_supported CHECK (purpose IN ('spending', 'ignore'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE spending_notification_formats DROP CONSTRAINT spending_notification_formats_purpose_supported');

        Schema::table('spending_notification_formats', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
