<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('counterparty');
            $table->string('direction');
            $table->string('currency', 3);
            $table->bigInteger('opening_balance_minor');
            $table->date('opened_on');
            $table->timestamps();
        });
        Schema::create('debt_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->bigInteger('amount_minor');
            $table->date('occurred_on');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_entries');
        Schema::dropIfExists('debts');
    }
};
