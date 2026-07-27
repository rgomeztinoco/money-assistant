<?php

use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner reviews and explicitly activates a Learned Rule from Corrections', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $firstTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Mercado Central',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);
    $secondTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'MERCADO—CENTRAL',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);
    $this->actingAs($owner);

    $this->put(route('transactions.category.update', $firstTransaction), [
        'expected_revision' => 1,
        'category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    visit('/transactions?selected='.$firstTransaction->id)
        ->assertSee('Create an exact Learned Rule?')
        ->assertSee('Nothing is activated automatically')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(LearnedRule::query()->count())->toBe(0);

    $this->put(route('transactions.category.update', $secondTransaction), [
        'expected_revision' => 1,
        'category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    visit('/learned-rules')
        ->assertSee('Supported by 2 separate Corrections.')
        ->assertSee('Mercado Central')
        ->press('Preview suggested rule')
        ->assertSee('Rule change preview')
        ->press('Confirm rule change')
        ->assertSee('Learned Rule activated.')
        ->assertSee('Rule lifecycle')
        ->assertSee('Revision 1')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(LearnedRule::query()->count())->toBe(1);
});

test('the owner previews creates historically applies and undoes a Learned Rule', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Restaurants']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Preview Cafe',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);
    $this->actingAs($owner);

    visit('/learned-rules')
        ->select('Category', (string) $category->id)
        ->fill('Merchant pattern', 'Preview Cafe')
        ->press('Preview new rule')
        ->assertSee('Rule change preview')
        ->assertSee('1 existing matches')
        ->press('Confirm rule change')
        ->assertSee('Learned Rule activated.')
        ->press('Preview historical application')
        ->assertSee('Historical application preview')
        ->assertSee('will create 1 authoritative Correction')
        ->press('Confirm 1 changes')
        ->assertSee('Historical Learned Rule application completed.')
        ->press('Undo group')
        ->assertSee('Historical application undone: 1 restored, 0 skipped.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($transaction->fresh()->category_id)->toBeNull();
});
