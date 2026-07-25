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
        Schema::create('open_claw_pending_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id')->unique();
            $table->string('service_key_id', 128);
            $table->unsignedSmallInteger('schema_version');
            $table->string('capability', 64);
            $table->char('conversation_digest', 64);
            $table->uuid('idempotency_key');
            $table->char('payload_digest', 64);
            $table->jsonb('payload');
            $table->string('effect_summary', 512);
            $table->unsignedInteger('prepared_revision')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->char('preparation_interaction_digest', 64);
            $table->timestamp('preparation_occurred_at');
            $table->timestamp('expires_at');
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['service_key_id', 'schema_version', 'capability', 'idempotency_key'],
                'open_claw_pending_operations_idempotency_unique',
            );
            $table->index(['user_id', 'conversation_digest']);
            $table->index('expires_at');
        });

        DB::statement('ALTER TABLE open_claw_pending_operations ADD CONSTRAINT open_claw_pending_operations_revision_valid CHECK (prepared_revision > 0 AND revision >= prepared_revision)');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX open_claw_pending_operations_one_active_per_conversation
            ON open_claw_pending_operations (user_id, conversation_digest)
            WHERE canceled_at IS NULL AND confirmed_at IS NULL
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS open_claw_pending_operations_one_active_per_conversation');
        Schema::dropIfExists('open_claw_pending_operations');
    }
};
