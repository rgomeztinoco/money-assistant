<?php

namespace Database\Factories;

use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantRule> */
class MerchantRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $merchant = fake()->company();

        return [
            'user_id' => User::factory(),
            'category_id' => fn (array $attributes): int => Category::factory()->create([
                'user_id' => $attributes['user_id'],
            ])->id,
            'merchant' => $merchant,
            'merchant_key' => app(MerchantNormalizer::class)->normalize($merchant),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => ['enabled' => false]);
    }
}
