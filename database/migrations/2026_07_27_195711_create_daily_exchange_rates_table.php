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
        Schema::create('daily_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('applicable_on');
            $table->bigInteger('pen_per_usd_scaled');
            $table->timestamp('owner_managed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'applicable_on']);
        });

        DB::statement('ALTER TABLE daily_exchange_rates ADD CONSTRAINT daily_exchange_rates_scaled_rate_positive CHECK (pen_per_usd_scaled > 0)');
        DB::statement('ALTER TABLE daily_exchange_rates ADD CONSTRAINT daily_exchange_rates_revision_positive CHECK (revision > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_exchange_rates');
    }
};
