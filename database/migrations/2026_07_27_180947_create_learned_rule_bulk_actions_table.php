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
        Schema::create('learned_rule_bulk_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learned_rule_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('learned_rule_revision');
            $table->string('rules_fingerprint', 64);
            $table->string('status', 32)->default('previewed');
            $table->timestampTz('preview_expires_at');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('undone_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE learned_rule_bulk_actions ADD CONSTRAINT learned_rule_bulk_actions_status_supported CHECK (status IN ('previewed', 'applied', 'undone'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learned_rule_bulk_actions');
    }
};
