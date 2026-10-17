<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yearly_payments', function (Blueprint $table): void {
            $table->string('due_month_day', 5)->nullable();
        });

        DB::table('yearly_payments')->whereNotNull('expected_due_on')->orderBy('id')->chunkById(500, function (Collection $payments): void {
            foreach ($payments as $payment) {
                DB::table('yearly_payments')->where('id', $payment->id)->update([
                    'due_month_day' => substr($payment->expected_due_on, 5, 5),
                ]);
            }
        });

        Schema::table('yearly_payments', function (Blueprint $table): void {
            $table->dropColumn('expected_due_on');
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('yearly_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->index('yearly_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('yearly_payment_id');
        });
        Schema::table('yearly_payments', function (Blueprint $table): void {
            $table->date('expected_due_on')->nullable();
        });
        DB::table('yearly_payments')->whereNotNull('due_month_day')->orderBy('id')->chunkById(500, function (Collection $payments): void {
            foreach ($payments as $payment) {
                DB::table('yearly_payments')->where('id', $payment->id)->update([
                    'expected_due_on' => '2000-'.$payment->due_month_day,
                ]);
            }
        });
        Schema::table('yearly_payments', function (Blueprint $table): void {
            $table->dropColumn('due_month_day');
        });
    }
};
