<?php

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
        ->and($owner->categories()->count())->toBe(49)
        ->and($owner->categories()->whereNull('parent_id')->count())->toBe(13)
        ->and($owner->categories()->where('name', 'Other')->exists())->toBeFalse();

    $paths = $owner->categories()
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
        'Education > Books',
        'Education > Courses',
        'Entertainment',
        'Entertainment > Events',
        'Entertainment > Hobbies',
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
        'Housing > Household Goods',
        'Housing > Household Services',
        'Housing > Rent',
        'Housing > Utilities',
        'Insurance',
        'Insurance > Health',
        'Insurance > Life',
        'Pets',
        'Pets > Food',
        'Pets > Medicine',
        'Pets > Supplies & Care',
        'Pets > Veterinary',
        'Shopping & Personal',
        'Shopping & Personal > Clothing',
        'Shopping & Personal > Electronics',
        'Shopping & Personal > Personal Care',
        'Shopping & Personal > Software & Digital Services',
        'Subscriptions',
        'Subscriptions > Media',
        'Subscriptions > Software',
        'Transport',
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
