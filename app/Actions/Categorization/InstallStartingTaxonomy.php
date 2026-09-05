<?php

namespace App\Actions\Categorization;

use App\Models\Category;
use App\Models\User;

final class InstallStartingTaxonomy
{
    /**
     * @var array<string, list<string>>
     */
    private const array TAXONOMY = [
        'Food & Drink' => ['Groceries', 'Restaurants', 'Delivery', 'Cafés'],
        'Housing' => ['Rent', 'Utilities', 'Household Services', 'Household Goods', 'Home Improvements'],
        'Transport' => ['Public Transit', 'Ride-hailing', 'Vehicle Maintenance'],
        'Health & Wellness' => ['Medical', 'Pharmacy', 'Fitness'],
        'Shopping & Personal' => ['Clothing', 'Electronics', 'Personal Care', 'Software & Digital Services'],
        'Entertainment' => ['Events', 'Hobbies'],
        'Subscriptions' => ['Software', 'Media'],
        'Education' => ['Courses', 'Books'],
        'Travel' => ['Flights', 'Lodging', 'Local Transport'],
        'Gifts & Donations' => [],
        'Fees & Taxes' => ['Bank Fees', 'Taxes'],
        'Insurance' => ['Health', 'Life'],
        'Pets' => ['Food', 'Veterinary', 'Medicine', 'Supplies & Care'],
    ];

    public function handle(User $owner): void
    {
        if (Category::query()->whereBelongsTo($owner, 'owner')->exists()) {
            return;
        }

        foreach (self::TAXONOMY as $parentName => $childNames) {
            $parent = Category::query()->create([
                'user_id' => $owner->getKey(),
                'name' => $parentName,
            ]);

            foreach ($childNames as $childName) {
                Category::query()->create([
                    'user_id' => $owner->getKey(),
                    'parent_id' => $parent->getKey(),
                    'name' => $childName,
                ]);
            }
        }
    }
}
