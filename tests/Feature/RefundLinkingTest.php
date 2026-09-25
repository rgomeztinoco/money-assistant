<?php

use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('an existing Refund relationship survives a direct Transaction edit', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'description' => 'Original purchase',
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'description' => 'Refund',
        'original_spending_id' => $spending->id,
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.update', $refund), [
            'occurred_on' => $refund->occurred_on->toDateString(),
            'amount_minor' => $refund->amount_minor,
            'currency' => 'PEN',
            'kind' => 'refund',
            'description' => 'Corrected Refund',
            'original_spending_id' => $spending->id,
        ])
        ->assertSessionHasNoErrors();

    expect($refund->refresh()->original_spending_id)->toBe($spending->id);

    $this->get(route('transactions.index', ['selected' => $refund->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.original_spending.id', $spending->id)));
});

test('the retired Refund link endpoint cannot change an existing Transaction', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->create();

    $this->actingAs($owner)
        ->post('/transactions/'.$refund->id.'/refund-link', ['spending_id' => $spending->id])
        ->assertNotFound();

    expect($refund->refresh()->original_spending_id)->toBeNull();
});
