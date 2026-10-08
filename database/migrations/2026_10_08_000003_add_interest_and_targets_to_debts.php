<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->bigInteger('monthly_target_minor')->nullable();
        });
        Schema::table('debt_entries', function (Blueprint $table): void {
            $table->bigInteger('principal_minor')->nullable();
            $table->bigInteger('interest_minor')->default(0);
            $table->foreignId('confirmed_with_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
        });
        DB::table('debt_entries')->whereNotNull('transaction_id')->update(['principal_minor' => DB::raw('amount_minor')]);
    }

    public function down(): void
    {
        Schema::table('debt_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_with_transaction_id');
            $table->dropColumn(['principal_minor', 'interest_minor']);
        });
        Schema::table('debts', function (Blueprint $table): void {
            $table->dropColumn('monthly_target_minor');
        });
    }
};
