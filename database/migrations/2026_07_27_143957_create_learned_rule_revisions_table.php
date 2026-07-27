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
        Schema::create('learned_rule_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learned_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('merchant_pattern');
            $table->string('merchant_key');
            $table->string('match_mode', 32);
            $table->string('transaction_kind', 32)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payment_instrument_label', 100)->nullable();
            $table->string('payment_instrument_last_four', 4)->nullable();
            $table->foreignId('source_category_assignment_id')
                ->nullable()
                ->constrained('category_assignments')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['learned_rule_id', 'revision']);
            $table->index(['merchant_key', 'match_mode']);
            $table->index('category_id');
        });

        DB::statement("ALTER TABLE learned_rule_revisions ADD CONSTRAINT learned_rule_revisions_match_mode_supported CHECK (match_mode IN ('exact', 'starts_with', 'contains'))");
        DB::statement("ALTER TABLE learned_rule_revisions ADD CONSTRAINT learned_rule_revisions_kind_supported CHECK (transaction_kind IS NULL OR transaction_kind IN ('purchase', 'refund'))");
        DB::statement("ALTER TABLE learned_rule_revisions ADD CONSTRAINT learned_rule_revisions_currency_supported CHECK (currency IS NULL OR currency IN ('USD', 'PEN'))");
        DB::statement("ALTER TABLE learned_rule_revisions ADD CONSTRAINT learned_rule_revisions_last_four_valid CHECK (payment_instrument_last_four IS NULL OR payment_instrument_last_four ~ '^[0-9]{4}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learned_rule_revisions');
    }
};
