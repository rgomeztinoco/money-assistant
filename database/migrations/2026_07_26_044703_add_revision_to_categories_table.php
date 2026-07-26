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
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_revision_positive CHECK (revision > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_revision_positive');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
