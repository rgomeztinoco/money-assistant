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
        Schema::create('category_target_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->date('effective_month');
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->timestamps();

            $table->unique(['category_target_id', 'revision']);
            $table->index(['category_target_id', 'effective_month', 'revision']);
        });

        DB::statement("ALTER TABLE category_target_revisions ADD CONSTRAINT category_target_revisions_effective_month_boundary CHECK (effective_month = date_trunc('month', effective_month)::date)");
        DB::statement('ALTER TABLE category_target_revisions ADD CONSTRAINT category_target_revisions_amount_nonnegative CHECK (amount_minor IS NULL OR amount_minor >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_target_revisions');
    }
};
