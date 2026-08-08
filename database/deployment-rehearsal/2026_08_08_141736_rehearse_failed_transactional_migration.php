<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('transactions')->update([
            'merchant_description' => 'Failed migration changed financial state',
        ]);

        throw new RuntimeException('Deliberate transactional deployment migration failure.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('transactions')->update([
            'merchant_description' => 'Transactional deployment baseline',
        ]);
    }
};
