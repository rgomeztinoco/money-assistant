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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->timestampTz('scheduled_for')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE reminders ADD CONSTRAINT reminders_revision_valid CHECK (revision > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
