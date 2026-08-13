<?php

namespace App\Actions\Categorization;

use App\Models\Category;

final class InstallStartingTaxonomy
{
    /**
     * @var array<string, list<string>>
     */
    private const array TAXONOMY = [
        'Food & Drink' => ['Groceries', 'Restaurants', 'Delivery', 'Cafés'],
        'Housing' => ['Rent', 'Utilities', 'Household', 'Home Improvements'],
        'Transport' => ['Public Transit', 'Ride-hailing', 'Fuel', 'Parking & Tolls', 'Vehicle Maintenance'],
        'Health & Wellness' => ['Medical', 'Pharmacy', 'Fitness'],
        'Shopping & Personal' => ['Clothing', 'Electronics', 'Personal Care'],
        'Entertainment' => ['Subscriptions', 'Events', 'Hobbies'],
        'Education' => ['Courses', 'Books & Supplies'],
        'Travel' => ['Flights', 'Lodging', 'Local Transport'],
        'Gifts & Donations' => [],
        'Fees & Taxes' => ['Bank Fees', 'Taxes'],
        'Pets' => ['Food', 'Veterinary', 'Medicine', 'Supplies & Care'],
    ];

    public function handle(): void
    {
        if (Category::query()->exists()) {
            return;
        }

        foreach (self::TAXONOMY as $parentName => $childNames) {
            $parent = Category::query()->create([
                'name' => $parentName,
            ]);

            foreach ($childNames as $childName) {
                Category::query()->create([
                    'parent_id' => $parent->getKey(),
                    'name' => $childName,
                ]);
            }
        }
    }
}
