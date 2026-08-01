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
        Schema::create('category_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->date('starts_on');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'category_id']);
            $table->index(['user_id', 'starts_on']);
        });

        DB::statement("ALTER TABLE category_targets ADD CONSTRAINT category_targets_currency_supported CHECK (currency IN ('USD', 'PEN'))");
        DB::statement("ALTER TABLE category_targets ADD CONSTRAINT category_targets_starts_on_month_boundary CHECK (starts_on = date_trunc('month', starts_on)::date)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_targets');
    }
};
