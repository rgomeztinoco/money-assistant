<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\YearlyPayment;
use Illuminate\Database\Seeder;

class YearlyPaymentSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', 'test@example.com')->first();

        if ($owner !== null) {
            YearlyPayment::query()->firstOrCreate(
                ['user_id' => $owner->id, 'name' => 'Sample annual insurance'],
                ['amount_minor' => 240000, 'currency' => 'PEN', 'cushion_minor' => 0, 'is_active' => true],
            );
        }
    }
}
