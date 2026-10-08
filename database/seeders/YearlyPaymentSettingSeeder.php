<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\YearlyPaymentSetting;
use Illuminate\Database\Seeder;

class YearlyPaymentSettingSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', 'test@example.com')->first();

        if ($owner !== null) {
            YearlyPaymentSetting::query()->firstOrCreate(['user_id' => $owner->id], ['pen_per_usd' => null]);
        }
    }
}
