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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('kind', 16);
            $table->string('merchant_description');
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_on', 'id']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_minor_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_currency_supported CHECK (currency IN ('USD', 'PEN'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_supported CHECK (kind IN ('purchase', 'refund'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
