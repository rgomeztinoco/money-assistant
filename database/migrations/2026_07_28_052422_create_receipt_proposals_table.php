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
        Schema::create('receipt_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('proposal_id');
            $table->string('source_kind', 32);
            $table->timestampTz('processed_at');
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->unsignedSmallInteger('contract_version');
            $table->jsonb('proposed_transaction');
            $table->jsonb('proposed_line_items');
            $table->timestamps();

            $table->unique(['user_id', 'proposal_id']);
        });

        DB::statement("ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_source_kind_supported CHECK (source_kind = 'receipt_photo')");
        DB::statement("ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_provider_supported CHECK (provider = 'openai')");
        DB::statement("ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_model_supported CHECK (model = 'openai/gpt-5.6')");
        DB::statement('ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_contract_version_supported CHECK (contract_version = 1)');
        DB::statement("ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_transaction_object CHECK (jsonb_typeof(proposed_transaction) = 'object')");
        DB::statement("ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_line_items_array CHECK (jsonb_typeof(proposed_line_items) = 'array' AND jsonb_array_length(proposed_line_items) BETWEEN 1 AND 200)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_proposals');
    }
};
