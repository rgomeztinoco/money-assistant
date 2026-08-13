<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('an operator can provision the Owner Account', function () {
    $this->artisan('owner:create')
        ->expectsQuestion('Owner name', 'Ricardo')
        ->expectsQuestion('Owner email', 'owner@example.com')
        ->expectsQuestion('Recovery password', 'a-secure-recovery-password')
        ->expectsOutput('Owner Account created. Register a passkey after signing in.')
        ->assertSuccessful();

    $owner = User::query()->sole();

    expect($owner->name)->toBe('Ricardo')
        ->and($owner->email)->toBe('owner@example.com')
        ->and(Hash::check('a-secure-recovery-password', $owner->password))->toBeTrue()
        ->and(Category::query()->count())->toBe(44)
        ->and(Category::query()->whereNull('parent_id')->count())->toBe(11)
        ->and(Category::query()->where('name', 'Other')->exists())->toBeFalse();

    $paths = Category::query()
        ->with('parent:id,name')
        ->get()
        ->map(fn ($category): string => $category->parent === null
            ? $category->name
            : $category->parent->name.' > '.$category->name)
        ->sort()
        ->values()
        ->all();

    expect($paths)->toBe([
        'Education',
        'Education > Books & Supplies',
        'Education > Courses',
        'Entertainment',
        'Entertainment > Events',
        'Entertainment > Hobbies',
        'Entertainment > Subscriptions',
        'Fees & Taxes',
        'Fees & Taxes > Bank Fees',
        'Fees & Taxes > Taxes',
        'Food & Drink',
        'Food & Drink > Cafés',
        'Food & Drink > Delivery',
        'Food & Drink > Groceries',
        'Food & Drink > Restaurants',
        'Gifts & Donations',
        'Health & Wellness',
        'Health & Wellness > Fitness',
        'Health & Wellness > Medical',
        'Health & Wellness > Pharmacy',
        'Housing',
        'Housing > Home Improvements',
        'Housing > Household',
        'Housing > Rent',
        'Housing > Utilities',
        'Pets',
        'Pets > Food',
        'Pets > Medicine',
        'Pets > Supplies & Care',
        'Pets > Veterinary',
        'Shopping & Personal',
        'Shopping & Personal > Clothing',
        'Shopping & Personal > Electronics',
        'Shopping & Personal > Personal Care',
        'Transport',
        'Transport > Fuel',
        'Transport > Parking & Tolls',
        'Transport > Public Transit',
        'Transport > Ride-hailing',
        'Transport > Vehicle Maintenance',
        'Travel',
        'Travel > Flights',
        'Travel > Local Transport',
        'Travel > Lodging',
    ]);
});

test('an operator cannot provision a second Owner Account', function () {
    User::factory()->create();

    $this->artisan('owner:create')
        ->expectsOutput('An Owner Account already exists.')
        ->assertFailed();
});
