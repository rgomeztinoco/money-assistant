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
        Schema::create('learned_rule_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_pattern');
            $table->string('merchant_key');
            $table->string('match_mode', 32);
            $table->string('transaction_kind', 32)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payment_instrument_label', 100)->nullable();
            $table->string('payment_instrument_last_four', 4)->nullable();
            $table->char('definition_hash', 64);
            $table->string('status', 32)->default('collecting');
            $table->unsignedInteger('evidence_count')->default(0);
            $table->timestampTz('dismissed_at')->nullable();
            $table->foreignId('accepted_rule_id')->nullable()->constrained('learned_rules')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'definition_hash']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        DB::statement("ALTER TABLE learned_rule_suggestions ADD CONSTRAINT learned_rule_suggestions_match_mode_supported CHECK (match_mode IN ('exact', 'starts_with', 'contains'))");
        DB::statement("ALTER TABLE learned_rule_suggestions ADD CONSTRAINT learned_rule_suggestions_kind_supported CHECK (transaction_kind IS NULL OR transaction_kind IN ('purchase', 'refund'))");
        DB::statement("ALTER TABLE learned_rule_suggestions ADD CONSTRAINT learned_rule_suggestions_currency_supported CHECK (currency IS NULL OR currency IN ('USD', 'PEN'))");
        DB::statement("ALTER TABLE learned_rule_suggestions ADD CONSTRAINT learned_rule_suggestions_last_four_valid CHECK (payment_instrument_last_four IS NULL OR payment_instrument_last_four ~ '^[0-9]{4}$')");
        DB::statement("ALTER TABLE learned_rule_suggestions ADD CONSTRAINT learned_rule_suggestions_status_supported CHECK (status IN ('collecting', 'pending', 'dismissed', 'accepted'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learned_rule_suggestions');
    }
};
