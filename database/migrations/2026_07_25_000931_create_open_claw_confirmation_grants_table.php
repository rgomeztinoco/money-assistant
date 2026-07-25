<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('open_claw_confirmation_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('grant_id')->unique();
            $table->foreignId('open_claw_pending_operation_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('service_key_id', 128);
            $table->unsignedSmallInteger('schema_version');
            $table->char('payload_digest', 64);
            $table->unsignedInteger('pending_operation_revision');
            $table->char('approval_interaction_digest', 64);
            $table->timestamp('approval_occurred_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_claw_confirmation_grants');
    }
};
