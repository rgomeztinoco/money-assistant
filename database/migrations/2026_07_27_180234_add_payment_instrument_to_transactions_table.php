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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_instrument_label', 100)->nullable()->after('merchant_description');
            $table->string('payment_instrument_last_four', 4)->nullable()->after('payment_instrument_label');
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_payment_instrument_last_four_valid CHECK (payment_instrument_last_four IS NULL OR payment_instrument_last_four ~ '^[0-9]{4}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_instrument_label', 'payment_instrument_last_four']);
        });
    }
};
