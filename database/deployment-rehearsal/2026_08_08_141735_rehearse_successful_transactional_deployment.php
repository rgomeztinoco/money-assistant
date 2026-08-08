<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ownerId = DB::table('users')->insertGetId([
            'name' => 'Deployment Rehearsal Owner',
            'email' => 'deployment-rehearsal@example.test',
            'password' => '$2y$12$012345678901234567890uER0H3vVA0m37YQXm0mWtQrQx5Sx7K9a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transactions')->insert([
            'user_id' => $ownerId,
            'occurred_on' => '2026-08-08',
            'amount_minor' => 7250,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => 'Transactional deployment baseline',
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('email', 'deployment-rehearsal@example.test')
            ->delete();
    }
};
