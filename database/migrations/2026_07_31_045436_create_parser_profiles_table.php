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
        Schema::create('parser_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('current_version')->default(1);
            $table->timestampTz('enabled_at')->nullable()->index();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX parser_profiles_owner_name_unique ON parser_profiles (user_id, lower(name))');
        DB::statement('ALTER TABLE parser_profiles ADD CONSTRAINT parser_profiles_current_version_positive CHECK (current_version > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parser_profiles');
    }
};
