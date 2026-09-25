<?php

use App\Actions\Ledger\ReadReviewQueue;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;

test('review workload still describes retained provisional and uncategorized Transactions', function () {
    $owner = User::factory()->create();
    $flagged = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->provisional([ReviewableTransactionField::Description])
        ->create(['description' => 'Imported market']);
    $other = User::factory()->create();
    Transaction::factory()
        ->for($other, 'owner')
        ->spending()
        ->provisional([ReviewableTransactionField::Description])
        ->create();

    $workload = app(ReadReviewQueue::class)->handle($owner);

    expect($workload['unresolved_field_count'])->toBe(1)
        ->and($workload['unresolved_category_count'])->toBe(1)
        ->and($workload['transactions'][0]['id'])->toBe($flagged->id)
        ->and($workload['transactions'][0]['fields'][0]['name'])->toBe('description');
});

test('the old Review Queue address redirects to Transactions instead of rendering a second screen', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->get(route('review_queue.index', ['item' => 'transaction:'.$transaction->id]))
        ->assertRedirect(route('transactions.index', ['selected' => $transaction->id]));
});

test('retired Review Queue mutations cannot change current Categories or fields', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->provisional([ReviewableTransactionField::Description])
        ->create();
    $this->actingAs($owner);

    $this->put('/review-queue/transactions/'.$transaction->id.'/category', [
        'category_id' => $category->id,
    ])->assertNotFound();
    $this->patch('/review-queue/'.$transaction->id.'/fields/description', [
        'description' => 'Altered',
    ])->assertNotFound();

    expect($transaction->refresh()->category_id)->toBeNull()
        ->and($transaction->provisional_fields)->toBe(['description']);
});

test('the legacy Review Queue address requires authentication', function () {
    $this->get(route('review_queue.index'))->assertRedirect(route('login'));
});
