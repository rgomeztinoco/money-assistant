<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\YearlyPaymentSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<YearlyPaymentSetting> */
class YearlyPaymentSettingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'pen_per_usd' => null];
    }
}
