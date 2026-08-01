<?php

use App\Actions\ReceiptReconciliation\AttachReceiptProposalToTransaction;
use App\Actions\Retention\PurgeExpiredFinancialData;
use App\Http\Middleware\RequirePasskeyConfirmation;
use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\FinancialDataTombstone;
use App\Models\LineItem;
use App\Models\OpenClawAuditEvent;
use App\Models\ReceiptBreakdown;
use App\Models\ReceiptProposal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('an eligible explicit deletion moves its payload into recoverable trash for thirty days', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Temporary Category',
        'description' => 'Recoverable financial payload',
        'examples' => ['Temporary expense'],
    ]);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertRedirect(route('categories.index'));

    expect(Category::find($category->id))->toBeNull();

    $trashedCategory = Category::onlyTrashed()->findOrFail($category->id);

    expect($trashedCategory)
        ->name->toBe('Temporary Category')
        ->description->toBe('Recoverable financial payload')
        ->examples->toBe(['Temporary expense'])
        ->deleted_at->toEqual(Date::now())
        ->purge_after->toEqual(Date::now()->addDays(30))
        ->deletion_id->not->toBeNull();
});

test('an owner can restore a deleted Category with its identity and payload from the Categories page', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Recover Me',
        'description' => 'Original description',
        'examples' => ['Original example'],
    ]);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1]);

    $trashedCategory = Category::onlyTrashed()->findOrFail($category->id);

    $this->post(route('categories.store'), [
        'name' => 'Recover Me',
        'parent_id' => null,
        'description' => null,
        'examples' => [],
    ])->assertSessionHasErrors('name');

    Date::setTestNow(Date::now()->addDays(29));

    $this->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('trashed_categories.0.deletion_id', $trashedCategory->deletion_id)
            ->where('trashed_categories.0.name', 'Recover Me')
            ->where('trashed_categories.0.purge_after', $trashedCategory->purge_after->toIso8601String()));

    $this->post(route('trash.categories.restoration.store', $trashedCategory->deletion_id))
        ->assertRedirect();

    $restoredCategory = Category::query()->findOrFail($category->id);

    expect($restoredCategory)
        ->id->toBe($category->id)
        ->name->toBe('Recover Me')
        ->description->toBe('Original description')
        ->examples->toBe(['Original example'])
        ->deleted_at->toBeNull()
        ->purge_after->toBeNull()
        ->deletion_id->toBeNull();
});

test('purge removes an expired payload and leaves only a payload-free tombstone', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Prohibited Tombstone Name',
        'description' => 'Prohibited tombstone description',
        'examples' => ['Prohibited tombstone example'],
    ]);
    $idempotencyKey = Str::uuid()->toString();
    $auditEvent = OpenClawAuditEvent::query()->create([
        'occurred_at' => Date::now(),
        'service_key_id' => 'openclaw-retention-test',
        'schema_version' => 1,
        'capability' => 'category.mutation.confirm',
        'outcome' => 'success',
        'http_status' => 200,
        'nonce_digest' => str_repeat('a', 64),
        'request_digest' => str_repeat('b', 64),
        'interaction_digest' => str_repeat('c', 64),
        'resource_type' => 'category',
        'result_count' => 1,
        'event_kind' => 'mutation',
        'idempotency_key' => $idempotencyKey,
        'operation_digest' => str_repeat('d', 64),
        'confirmation_grant_id' => Str::uuid()->toString(),
        'domain_action' => 'category.create',
        'resource_id' => $category->id,
        'resource_revision' => 1,
    ]);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1]);

    $deletedCategory = Category::onlyTrashed()->findOrFail($category->id);
    $deletionId = $deletedCategory->deletion_id;
    $deletedAt = $deletedCategory->deleted_at;

    Date::setTestNow(Date::now()->addDays(30));

    expect(app(PurgeExpiredFinancialData::class)->handle())->toBe(1)
        ->and(Category::withTrashed()->find($category->id))->toBeNull();

    $tombstone = FinancialDataTombstone::query()->sole();
    $serializedTombstone = json_encode($tombstone->getAttributes(), JSON_THROW_ON_ERROR);

    expect($tombstone)
        ->id->toBe($deletionId)
        ->owner_id->toBe($owner->id)
        ->resource_type->toBe('category')
        ->resource_id->toBe($category->id)
        ->deleted_at->toEqual($deletedAt)
        ->purged_at->toEqual(Date::now())
        ->and($serializedTombstone)
        ->not->toContain('Prohibited Tombstone Name')
        ->not->toContain('Prohibited tombstone description')
        ->not->toContain('Prohibited tombstone example')
        ->and($auditEvent->fresh())
        ->id->toBe($auditEvent->id)
        ->idempotency_key->toBe($idempotencyKey)
        ->resource_id->toBe($tombstone->resource_id);
});

test('a Category referenced by another protected financial resource cannot enter trash', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    CategoryTarget::factory()->for($owner, 'owner')->for($category)->create();

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertSessionHasErrors('category');

    expect($category->fresh()->deleted_at)->toBeNull()
        ->and(Category::onlyTrashed()->count())->toBe(0);
});

test('expired financial trash is scheduled for automatic purge', function () {
    expect(collect(Schedule::events())->contains(
        fn ($event): bool => $event->description === 'expired-financial-data-purge',
    ))->toBeTrue();
});

test('financial data tombstones are database-enforced append-only records', function (string $operation) {
    $tombstone = FinancialDataTombstone::query()->create([
        'id' => Str::uuid()->toString(),
        'owner_id' => 1,
        'resource_type' => 'category',
        'resource_id' => 123,
        'deleted_at' => now()->subDays(30),
        'purged_at' => now(),
    ]);

    expect(fn () => DB::transaction(function () use ($operation, $tombstone): void {
        if ($operation === 'update') {
            FinancialDataTombstone::query()
                ->whereKey($tombstone->id)
                ->update(['resource_id' => 456]);

            return;
        }

        FinancialDataTombstone::query()->whereKey($tombstone->id)->delete();
    }))->toThrow(QueryException::class);
})->with(['update', 'delete']);

test('explicit draft discard trashes the Receipt Breakdown and its Line Item payload', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->draft()
        ->create();
    $lineItem = LineItem::factory()->for($breakdown)->create([
        'description' => 'Recoverable Line Item',
    ]);

    $this->actingAs($owner)
        ->delete(route('receipt_breakdowns.destroy', $breakdown), [
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect(ReceiptBreakdown::find($breakdown->id))->toBeNull();

    $trashedBreakdown = ReceiptBreakdown::onlyTrashed()->findOrFail($breakdown->id);

    expect($trashedBreakdown)
        ->deletion_id->not->toBeNull()
        ->purge_after->toEqual(Date::now()->addDays(30))
        ->and($lineItem->fresh())
        ->description->toBe('Recoverable Line Item')
        ->receipt_breakdown_id->toBe($breakdown->id);
});

test('an owner can restore a discarded Receipt Breakdown with its Line Items from the inspector', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->draft()
        ->create();
    $lineItem = LineItem::factory()->for($breakdown)->create();

    $this->actingAs($owner)->delete(route('receipt_breakdowns.destroy', $breakdown), [
        'expected_revision' => 1,
    ]);

    $trashedBreakdown = ReceiptBreakdown::onlyTrashed()->findOrFail($breakdown->id);

    Date::setTestNow(Date::now()->addDays(29));

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.trashed_receipt_breakdowns.0.deletion_id', $trashedBreakdown->deletion_id)
            ->where('selected_transaction.trashed_receipt_breakdowns.0.revision', 1)
            ->where(
                'selected_transaction.trashed_receipt_breakdowns.0.purge_after',
                $trashedBreakdown->purge_after->toIso8601String(),
            ));

    $this->post(route(
        'trash.receipt_breakdowns.restoration.store',
        $trashedBreakdown->deletion_id,
    ))->assertRedirect();

    $restoredBreakdown = ReceiptBreakdown::query()->findOrFail($breakdown->id);

    expect($restoredBreakdown)
        ->id->toBe($breakdown->id)
        ->deleted_at->toBeNull()
        ->purge_after->toBeNull()
        ->deletion_id->toBeNull()
        ->and($restoredBreakdown->lineItems()->sole()->id)
        ->toBe($lineItem->id);
});

test('purge removes an expired Receipt Breakdown payload and its Line Items', function () {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->draft()
        ->create();
    $lineItem = LineItem::factory()->for($breakdown)->create([
        'description' => 'Prohibited purged Line Item',
    ]);

    $this->actingAs($owner)->delete(route('receipt_breakdowns.destroy', $breakdown), [
        'expected_revision' => 1,
    ]);

    $trashedBreakdown = ReceiptBreakdown::onlyTrashed()->findOrFail($breakdown->id);

    Date::setTestNow(Date::now()->addDays(30));

    expect(app(PurgeExpiredFinancialData::class)->handle())->toBe(1)
        ->and(ReceiptBreakdown::withTrashed()->find($breakdown->id))->toBeNull()
        ->and($lineItem->fresh())->toBeNull();

    $tombstone = FinancialDataTombstone::query()->sole();

    expect($tombstone)
        ->id->toBe($trashedBreakdown->deletion_id)
        ->resource_type->toBe('receipt_breakdown')
        ->resource_id->toBe($breakdown->id)
        ->and(json_encode($tombstone->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('Prohibited purged Line Item');
});

test('a Receipt Proposal reference cannot be repurposed after its Breakdown is discarded', function (bool $purged) {
    Date::setTestNow('2026-08-01 10:00:00');

    $owner = User::factory()->create();
    $sourceTransaction = Transaction::factory()->for($owner, 'owner')->create();
    $targetTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create();
    $proposal = ReceiptProposal::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($sourceTransaction)
        ->for($proposal, 'receiptProposal')
        ->draft()
        ->create();

    $this->actingAs($owner)->delete(route('receipt_breakdowns.destroy', $breakdown), [
        'expected_revision' => 1,
    ]);

    if ($purged) {
        Date::setTestNow(Date::now()->addDays(30));
        app(PurgeExpiredFinancialData::class)->handle();
    }

    expect(fn () => app(AttachReceiptProposalToTransaction::class)->handle(
        $owner,
        $targetTransaction,
        $proposal->proposal_id,
    ))->toThrow(ValidationException::class);
})->with([
    'during trash' => [false],
    'after purge' => [true],
]);

test('a discarded Receipt Breakdown reserves its Transaction draft slot during trash', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create();
    $firstProposal = ReceiptProposal::factory()->for($owner, 'owner')->create();
    $replacementProposal = ReceiptProposal::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->for($firstProposal, 'receiptProposal')
        ->draft()
        ->create();

    $this->actingAs($owner)->delete(route('receipt_breakdowns.destroy', $breakdown), [
        'expected_revision' => 1,
    ]);

    expect(fn () => app(AttachReceiptProposalToTransaction::class)->handle(
        $owner,
        $transaction,
        $replacementProposal->proposal_id,
    ))->toThrow(
        ValidationException::class,
        'This Transaction already has a draft Receipt Breakdown, including recoverable trash.',
    );
});
