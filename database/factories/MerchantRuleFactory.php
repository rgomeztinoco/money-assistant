<?php

namespace Database\Factories;

use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\MerchantRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantRule> */
class MerchantRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $merchant = fake()->company();

        return [
            'category_id' => Category::factory(),
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
