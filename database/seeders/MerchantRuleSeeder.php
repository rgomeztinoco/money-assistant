<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\User;
use Illuminate\Database\Seeder;

class MerchantRuleSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->latest('id')->first();
        $category = Category::query()->whereNull('retired_at')->latest('id')->first();

        if ($owner === null || $category === null) {
            return;
        }

        MerchantRule::factory()->for($owner, 'owner')->for($category)->create();
    }
}
