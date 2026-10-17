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

        if ($owner === null) {
            return;
        }

        $payments = [
            ['name' => 'Sample annual insurance', 'amount_minor' => 240000, 'currency' => 'PEN', 'cushion_minor' => 12000, 'due_month_day' => '11-15', 'is_active' => true],
            ['name' => 'Sample vehicle insurance', 'amount_minor' => 180000, 'currency' => 'PEN', 'cushion_minor' => 10000, 'due_month_day' => '12-05', 'is_active' => true],
            ['name' => 'Sample property tax', 'amount_minor' => 96000, 'currency' => 'PEN', 'cushion_minor' => 0, 'due_month_day' => '02-28', 'is_active' => true],
            ['name' => 'Sample professional membership', 'amount_minor' => 48000, 'currency' => 'PEN', 'cushion_minor' => 0, 'due_month_day' => '04-10', 'is_active' => true],
            ['name' => 'Sample annual checkup', 'amount_minor' => 65000, 'currency' => 'PEN', 'cushion_minor' => 15000, 'due_month_day' => null, 'is_active' => true],
            ['name' => 'Sample gym membership', 'amount_minor' => 120000, 'currency' => 'PEN', 'cushion_minor' => 0, 'due_month_day' => '01-08', 'is_active' => true],
            ['name' => 'Sample cloud storage', 'amount_minor' => 9999, 'currency' => 'USD', 'cushion_minor' => 1000, 'due_month_day' => '10-25', 'is_active' => true],
            ['name' => 'Sample design software', 'amount_minor' => 29900, 'currency' => 'USD', 'cushion_minor' => 3000, 'due_month_day' => '11-02', 'is_active' => true],
            ['name' => 'Sample domain renewal', 'amount_minor' => 1800, 'currency' => 'USD', 'cushion_minor' => 0, 'due_month_day' => '06-18', 'is_active' => true],
            ['name' => 'Sample password manager', 'amount_minor' => 3600, 'currency' => 'USD', 'cushion_minor' => 0, 'due_month_day' => '08-12', 'is_active' => true],
            ['name' => 'Sample language course', 'amount_minor' => 150000, 'currency' => 'PEN', 'cushion_minor' => 0, 'due_month_day' => '03-01', 'is_active' => false],
            ['name' => 'Sample streaming subscription', 'amount_minor' => 8999, 'currency' => 'USD', 'cushion_minor' => 0, 'due_month_day' => '07-20', 'is_active' => false],
        ];

        foreach ($payments as $payment) {
            YearlyPayment::query()->firstOrCreate(
                ['user_id' => $owner->id, 'name' => $payment['name']],
                $payment,
            );
        }
    }
}
