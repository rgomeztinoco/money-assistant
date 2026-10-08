<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Jobs\ProcessGmailMessage;
use App\Models\Category;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeGmail;
use Tests\SyntheticPdf;

test('the owner starts a debt from the current balance without creating a cash movement', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/debts', [
        'name' => 'Family loan', 'counterparty' => 'Mother', 'direction' => 'owed',
        'currency' => 'PEN', 'opening_balance_minor' => '125001', 'opened_on' => '2026-08-01',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->get('/debts')->assertInertia(fn (Assert $page) => $page
        ->component('debts/index')->has('debts', 1)
        ->where('debts.0.name', 'Family loan')->where('debts.0.balance_minor', '125001')
        ->where('debts.0.status', 'active'));
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 0));
});

test('manual principal movements and non-cash adjustments keep exact balances and settled history', function (string $direction, string $fundingDirection, string $repaymentDirection) {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/debts', [
        'name' => 'Running loan', 'counterparty' => 'Family', 'direction' => $direction,
        'currency' => 'PEN', 'opening_balance_minor' => '10001', 'opened_on' => '2026-08-01',
    ])->assertSessionHasNoErrors();
    // Read the new identifier through the owner-facing list.
    $debtId = $this->get('/debts')->inertiaProps('debts.0.id');
    $this->post("/debts/{$debtId}/entries", ['kind' => 'funding', 'occurred_on' => '2026-08-02', 'amount' => '20.02', 'description' => 'Additional loan'])->assertSessionHasNoErrors();
    $this->post("/debts/{$debtId}/entries", ['kind' => 'adjustment', 'occurred_on' => '2026-08-03', 'amount_minor' => '-3', 'reason' => 'Balance correction'])->assertSessionHasNoErrors();
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-04', 'amount' => '120.00', 'description' => 'Repayment'])->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page
        ->where('debt.balance_minor', '0')->where('debt.status', 'settled')->has('entries', 3));
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 2)
        ->where('transactions.0.kind', 'debt')->where('transactions.0.direction', $repaymentDirection)
        ->where('transactions.1.direction', $fundingDirection));
    $this->post("/debts/{$debtId}/entries", ['kind' => 'funding', 'occurred_on' => '2026-08-05', 'amount_minor' => '1', 'description' => 'New borrowing'])->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '1')->where('debt.status', 'active')->has('entries', 4));
})->with([['owed', 'credit', 'debit'], ['receivable', 'debit', 'credit']]);

test('allocating posted principal preserves identity and updates reports and reversible corrections', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/debts', ['name' => 'Loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '10000', 'opened_on' => '2026-08-01']);
    $debtId = $this->get('/debts')->inertiaProps('debts.0.id');
    $payment = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-08-05', 'amount_minor' => 3001]);
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'transaction_id' => $payment->id])->assertSessionHasNoErrors();
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 1)->where('transactions.0.id', $payment->id)->where('transactions.0.amount_minor', '3001')->where('transactions.0.kind', 'debt'));
    $this->get('/breakdown?currency=PEN&period=custom&date_from=2026-08-01&date_to=2026-08-31')->assertInertia(fn (Assert $page) => $page->where('summary.PEN.net_spending_minor', '0')->where('summary.PEN.income_minor', '0')->where('summary.PEN.debt_payments_made_minor', '3001')->where('summary.PEN.debt_payments_received_minor', '0'));
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'transaction_id' => $payment->id])->assertSessionHasErrors('transaction_id');
    $this->post(route('transactions.void.store', $payment))->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '10000')->where('entries.0.voided', true));
    $this->delete(route('transactions.void.destroy', $payment))->assertSessionHasNoErrors();
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-08-06', 'amount_minor' => '4002', 'principal_minor' => '4002', 'interest_minor' => '0', 'currency' => 'PEN', 'kind' => 'debt', 'direction' => 'debit', 'description' => 'Corrected payment'])->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '5998')->where('entries.0.amount_minor', '4002')->where('entries.0.occurred_on', '2026-08-06'));
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-08-06', 'amount_minor' => '4002', 'currency' => 'USD', 'kind' => 'debt', 'direction' => 'debit', 'description' => 'Invalid correction'])->assertSessionHasErrors('currency');
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-07-31', 'amount_minor' => '4002', 'principal_minor' => '4002', 'interest_minor' => '0', 'currency' => 'PEN', 'kind' => 'debt', 'direction' => 'debit', 'description' => 'Before baseline'])->assertSessionHasErrors('occurred_on');
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-08-06', 'amount_minor' => '4002', 'currency' => 'PEN', 'kind' => 'spending', 'direction' => 'debit', 'description' => 'Unlinked'])->assertSessionHasErrors('unlink_debt');
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-08-06', 'amount_minor' => '4002', 'currency' => 'PEN', 'kind' => 'spending', 'direction' => 'debit', 'description' => 'Unlinked', 'unlink_debt' => true])->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '10000')->has('entries', 0));
});

test('debt allocations reject incompatible, foreign, invalid, and historical movements atomically', function () {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $this->actingAs($owner)->post('/debts', ['name' => 'Loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '9223372036854775807', 'opened_on' => '2026-08-01']);
    $debtId = $this->get('/debts')->inertiaProps('debts.0.id');
    $this->post("/debts/{$debtId}/entries", ['kind' => 'funding', 'occurred_on' => '2026-08-02', 'amount_minor' => '1', 'description' => 'One cent'])->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '9223372036854775808'));
    $wrongCurrency = Transaction::factory()->for($owner, 'owner')->usd()->create(['occurred_on' => '2026-08-05']);
    $wrongDirection = Transaction::factory()->for($owner, 'owner')->pen()->refund()->create(['occurred_on' => '2026-08-05']);
    $foreignPayment = Transaction::factory()->for($foreign, 'owner')->pen()->create(['occurred_on' => '2026-08-05']);
    foreach ([[$wrongCurrency, 'currency'], [$wrongDirection, 'direction'], [$foreignPayment, 'transaction_id']] as [$payment, $error]) {
        $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'transaction_id' => $payment->id])->assertSessionHasErrors($error);
    }
    foreach (['0', '-1', '1.5', '1e2', '9223372036854775808'] as $invalidAmount) {
        $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-03', 'amount_minor' => $invalidAmount, 'description' => 'Invalid'])->assertSessionHasErrors('amount_minor');
    }
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-07-31', 'amount_minor' => '1', 'description' => 'Before baseline'])->assertSessionHasErrors('occurred_on');
    $this->post("/debts/{$debtId}/entries", ['kind' => 'adjustment', 'occurred_on' => '2026-08-03', 'amount_minor' => '-1'])->assertSessionHasErrors('reason');
    $this->post('/transactions', ['kind' => 'debt', 'occurred_on' => '2026-08-03', 'currency' => 'PEN', 'amount_minor' => '100', 'description' => 'Unallocated'])->assertSessionHasErrors('kind');
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 3));
    $this->actingAs($foreign)->get('/debts')->assertInertia(fn (Assert $page) => $page->has('debts', 0));
    $this->get("/debts/{$debtId}")->assertNotFound();
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment'])->assertForbidden();
    $this->put("/debts/{$debtId}", [])->assertForbidden();
    auth()->logout();
    $this->get('/debts')->assertRedirect(route('login'));
    $this->post('/debts', [])->assertRedirect(route('login'));
});

test('payments received and reassignment stay separate by currency and debt direction', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    foreach (['First loan', 'Second loan'] as $name) {
        $this->post('/debts', ['name' => $name, 'counterparty' => 'Friend', 'direction' => 'receivable', 'currency' => 'USD', 'opening_balance_minor' => '10000', 'opened_on' => '2026-08-01'])->assertSessionHasNoErrors();
    }
    $debts = $this->get('/debts')->inertiaProps('debts');
    $this->post("/debts/{$debts[0]['id']}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-02', 'amount' => '25.01', 'description' => 'Collection'])->assertSessionHasNoErrors();
    $paymentId = $this->get('/transactions')->inertiaProps('transactions.0.id');
    $this->get('/breakdown?period=custom&date_from=2026-08-01&date_to=2026-08-31')->assertInertia(fn (Assert $page) => $page->where('summary.USD.income_minor', '0')->where('summary.USD.debt_payments_received_minor', '2501')->where('summary.PEN.debt_payments_received_minor', '0'));
    $this->put("/transactions/{$paymentId}", ['kind' => 'debt', 'direction' => 'credit', 'currency' => 'USD', 'amount_minor' => '2501', 'occurred_on' => '2026-08-02', 'description' => 'Collection', 'debt_id' => $debts[1]['id'], 'debt_entry_kind' => 'repayment'])->assertSessionHasNoErrors();
    $this->get("/debts/{$debts[0]['id']}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '10000'));
    $this->get("/debts/{$debts[1]['id']}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '7499'));
    $this->put("/debts/{$debts[1]['id']}", ['name' => 'Renamed loan', 'counterparty' => 'Friend', 'direction' => 'owed', 'currency' => 'USD', 'opening_balance_minor' => '10000', 'opened_on' => '2026-08-01'])->assertSessionHasErrors('currency');
    $this->put("/debts/{$debts[1]['id']}", ['name' => 'Renamed loan', 'counterparty' => 'Friend', 'direction' => 'receivable', 'currency' => 'USD', 'opening_balance_minor' => '10000', 'opened_on' => '2026-08-03'])->assertSessionHasErrors('opened_on');
});

test('Statement Import reuses a reviewed Debt Transaction once at its full posted amount', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Interest fees']);
    $this->actingAs($owner)->post('/debts', ['name' => 'Loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '10000', 'opened_on' => '2026-02-01']);
    $debtId = $this->get('/debts')->inertiaProps('debts.0.id');
    $payment = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-02-04', 'amount_minor' => 1000, 'description' => 'Pago YAPE a 123456', 'instrument_label' => 'BCP Cuenta Digital']);
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'transaction_id' => $payment->id, 'principal_minor' => '700', 'interest_minor' => '300', 'interest_is_new' => true, 'category_id' => $category->id])->assertSessionHasNoErrors();
    $statement = UploadedFile::fake()->createWithContent('statement.pdf', SyntheticPdf::fromText(file_get_contents(__DIR__.'/../Fixtures/Statements/bcp.txt')));
    $preview = $this->post('/statement-import-previews', ['statement' => $statement])->assertOk()->json();
    expect($preview['movements'][3]['classification'])->toBe('debt')
        ->and($preview['movements'][3]['match']['transaction_id'])->toBe($payment->id);
    $movements = array_map(fn ($movement) => [
        'source_row_id' => $movement['source_row_id'], 'occurred_on' => $movement['occurred_on'], 'description' => $movement['description'], 'amount_minor' => $movement['amount_minor'], 'currency' => $movement['currency'],
        'classification' => $movement['classification'] === 'needs_classification' ? 'transfer' : $movement['classification'],
        'resolution' => $movement['match']['transaction_id'] === null ? 'create' : 'link', 'transaction_id' => $movement['match']['transaction_id'], 'owner_confirmed_match' => false,
    ], $preview['movements']);
    $this->post('/statement-imports', ['statement' => $statement, 'file_hash' => $preview['file_hash'], 'financial_statement_format' => $preview['financial_statement_format'], 'instrument_label' => $preview['instrument_label'], 'instrument_last_four' => $preview['instrument_last_four'], 'movements' => $movements])->assertSessionHasNoErrors()->assertRedirect();
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 5));
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '9300')->has('entries', 2)->where('entries.0.principal_minor', '700')->where('entries.0.interest_minor', '300')->where('entries.0.transaction_id', $payment->id));
    $category->update(['archived_at' => now()]);
    $statementUrl = $this->get('/statement-imports')->inertiaProps('statement_imports.data.0.id');
    $movementId = $this->get("/statement-imports/{$statementUrl}")->inertiaProps('statement_import.movements.3.id');
    $this->put("/statement-imports/{$statementUrl}/movements/{$movementId}/classification", ['classification' => 'debt'])->assertSessionHasNoErrors();
    $this->get('/transactions?search='.$payment->id)->assertInertia(fn (Assert $page) => $page->where('transactions.0.category.id', $category->id)->where('transactions.0.debt_allocation.interest_minor', '300'));
    $this->put(route('transactions.update', $payment), ['kind' => 'debt', 'direction' => 'debit', 'currency' => 'PEN', 'amount_minor' => '2000', 'principal_minor' => '1700', 'interest_minor' => '300', 'occurred_on' => '2026-02-04', 'description' => 'Corrected posted amount'])->assertSessionHasNoErrors();
    $this->get("/statement-imports/{$statementUrl}")->assertInertia(fn (Assert $page) => $page->where('statement_import.summary.PEN.debt_payments_made_minor', '2000')->where('statement_import.summary.PEN.spending_minor', '301')->where('statement_import.movements.3.amount_minor', '1000'));
    $this->get('/?currency=PEN&period=custom&date_from=2026-02-01&date_to=2026-02-28')->assertInertia(fn (Assert $page) => $page->where('primary.summary.debt_payments_made_minor', '2000')->where('primary.summary.net_spending_minor', '301'));
    $this->get('/trends?currency=PEN&period=custom&date_from=2026-02-01&date_to=2026-02-28')->assertInertia(fn (Assert $page) => $page->where('summary.debt_payments_made_minor', '2000')->where('summary.net_spending_minor', '301'));
    $this->post(route('transactions.void.store', $payment))->assertSessionHasNoErrors();
    $this->get('/breakdown?period=custom&date_from=2026-02-01&date_to=2026-02-28')->assertInertia(fn (Assert $page) => $page->where('summary.PEN.debt_payments_made_minor', '0')->where('summary.PEN.net_spending_minor', '1'));
    $this->delete(route('transactions.void.destroy', $payment))->assertSessionHasNoErrors();
    $this->get('/breakdown?period=custom&date_from=2026-02-01&date_to=2026-02-28')->assertInertia(fn (Assert $page) => $page->where('summary.PEN.debt_payments_made_minor', '2000')->where('summary.PEN.net_spending_minor', '301'));
    $this->put(route('transactions.update', $payment), ['kind' => 'spending', 'direction' => 'debit', 'currency' => 'PEN', 'amount_minor' => '2000', 'principal_minor' => '1700', 'interest_minor' => '300', 'occurred_on' => '2026-02-04', 'description' => 'Unlinked correction', 'unlink_debt' => true])->assertSessionHasNoErrors();
    $this->get('/breakdown?period=custom&date_from=2026-02-01&date_to=2026-02-28')->assertInertia(fn (Assert $page) => $page->where('summary.PEN.debt_payments_made_minor', '0')->where('summary.PEN.net_spending_minor', '2001'));

});

test('ambiguous statement debt matches require owner review and cannot create unallocated debts', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/debts', ['name' => 'Loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '10000', 'opened_on' => '2026-02-01']);
    $debtId = $this->get('/debts')->inertiaProps('debts.0.id');
    foreach ([1, 2] as $number) {
        $payment = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-02-04', 'amount_minor' => 1000, 'description' => 'Pago YAPE a 123456']);
        $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'transaction_id' => $payment->id])->assertSessionHasNoErrors();
    }
    $statement = UploadedFile::fake()->createWithContent('statement.pdf', SyntheticPdf::fromText(file_get_contents(__DIR__.'/../Fixtures/Statements/bcp.txt')));
    $preview = $this->post('/statement-import-previews', ['statement' => $statement])->assertOk()->json();
    expect($preview['movements'][3]['match']['status'])->toBe('ambiguous')->and($preview['movements'][3]['match']['candidates'])->toHaveCount(2);
    $movements = array_map(fn ($movement) => ['source_row_id' => $movement['source_row_id'], 'occurred_on' => $movement['occurred_on'], 'description' => $movement['description'], 'amount_minor' => $movement['amount_minor'], 'currency' => $movement['currency'], 'classification' => $movement['classification'] === 'needs_classification' ? 'transfer' : $movement['classification'], 'resolution' => $movement['match']['status'] === 'ambiguous' && $movement['match']['candidates'] !== [] ? 'needs_resolution' : 'create', 'transaction_id' => null, 'owner_confirmed_match' => false], $preview['movements']);
    $confirmation = ['statement' => $statement, 'file_hash' => $preview['file_hash'], 'instrument_label' => $preview['instrument_label'], 'instrument_last_four' => $preview['instrument_last_four'], 'movements' => $movements];
    $this->post('/statement-imports', $confirmation)->assertSessionHasErrors('movements.3.resolution');
    $confirmation['movements'][3] = [...$movements[3], 'classification' => 'debt', 'resolution' => 'create'];
    $this->post('/statement-imports', $confirmation)->assertSessionHasErrors('movements.3.classification');
    $confirmation['movements'][3] = [...$movements[3], 'classification' => 'debt', 'resolution' => 'link', 'transaction_id' => $preview['movements'][3]['match']['candidates'][0]['id'], 'owner_confirmed_match' => true];
    $this->post('/statement-imports', $confirmation)->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '8000')->has('entries', 2));
});

test('reprocessing the same Gmail source preserves its reviewed debt payment and source identity', function () {
    $connection = GmailConnection::factory()->create(['access_token' => 'test-token', 'access_token_expires_at' => now()->addHour()]);
    $owner = $connection->owner;
    $fixture = json_decode(file_get_contents(resource_path('notification-formats/yape-outgoing-spending.json')), true, flags: JSON_THROW_ON_ERROR)['message'];
    $message = new GmailMessage(messageId: $fixture['message_id'], receivedAt: CarbonImmutable::parse($fixture['received_at']), fromAddress: $fixture['from_address'], subject: $fixture['subject'], authentication: $fixture['authentication'], textBody: null, htmlBody: $fixture['html_body']);
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create(['message_id' => $message->messageId]);
    $gmail = new FakeGmail;
    $gmail->messages = [$message->messageId => $message];
    app()->instance(Gmail::class, $gmail);
    $job = new ProcessGmailMessage($discovery->id);
    app()->call([$job, 'handle']);
    $this->actingAs($owner)->post('/debts', ['name' => 'Family loan', 'counterparty' => 'Family', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '100000', 'opened_on' => '2026-01-01']);
    $debtId = $this->get('/debts')->inertiaProps('debts.0.id');
    $paymentId = $this->get('/transactions')->inertiaProps('transactions.0.id');
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'transaction_id' => $paymentId])->assertSessionHasNoErrors();
    $balance = $this->get("/debts/{$debtId}")->inertiaProps('debt.balance_minor');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 1)->where('transactions.0.kind', 'debt')->where('transactions.0.id', $paymentId));
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', $balance)->has('entries', 1)->where('entries.0.transaction_id', $paymentId));
    expect($gmail->messageCalls)->toHaveCount(1);
});

test('manual and newly confirmed interest charges leave one exact allocated posted repayment', function (bool $alreadyCharged) {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/debts', ['name' => 'Bank loan', 'counterparty' => 'Bank', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '100000', 'opened_on' => '2026-08-01']);
    $id = $this->get('/debts')->inertiaProps('debts.0.id');
    if ($alreadyCharged) {
        $this->post("/debts/{$id}/entries", ['kind' => 'interest_charge', 'occurred_on' => '2026-08-04', 'amount' => '30.00', 'reason' => 'Confirmed statement interest'])->assertSessionHasNoErrors();
    }
    $this->post("/debts/{$id}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-05', 'amount' => '200.00', 'description' => 'Bank repayment', 'principal_minor' => '17000', 'interest_minor' => '3000', 'interest_is_new' => ! $alreadyCharged])->assertSessionHasNoErrors();
    $this->get("/debts/{$id}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '83000')->has('entries', 2)->where('entries.0.principal_minor', '17000')->where('entries.0.interest_minor', '3000'));
    $paymentId = $this->get('/transactions')->inertiaProps('transactions.0.id');
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 1)->where('transactions.0.amount_minor', '20000')->where('transactions.0.debt_allocation.interest_minor', '3000'));
    $this->post(route('transactions.void.store', $paymentId))->assertSessionHasNoErrors();
    $this->get("/debts/{$id}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '103000')->has('entries', 2));
    $this->delete(route('transactions.void.destroy', $paymentId))->assertSessionHasNoErrors();
    $this->get("/debts/{$id}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '83000')->has('entries', 2));
})->with([true, false]);

test('paid and received interest agree across financial reads without including principal', function (string $direction, string $currency) {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Loan interest']);
    $this->actingAs($owner)->post('/debts', ['name' => 'Loan', 'counterparty' => 'Bank', 'direction' => $direction, 'currency' => $currency, 'opening_balance_minor' => '100000', 'opened_on' => '2026-08-01']);
    $id = $this->get('/debts')->inertiaProps('debts.0.id');
    $this->post("/debts/{$id}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-05', 'amount' => '200.00', 'description' => 'Bank repayment', 'principal_minor' => '17000', 'interest_minor' => '3000', 'interest_is_new' => true, 'category_id' => $direction === 'owed' ? $category->id : null])->assertSessionHasNoErrors();
    $query = "currency={$currency}&period=custom&date_from=2026-08-01&date_to=2026-08-31";
    $spending = $direction === 'owed' ? '3000' : '0';
    $income = $direction === 'receivable' ? '3000' : '0';
    foreach (['/breakdown', '/trends', '/'] as $path) {
        $response = $this->get($path.'?'.$query);
        $prefix = match ($path) {
            '/breakdown' => 'summary.'.$currency, '/trends' => 'summary', default => 'primary.summary'
        };
        $response->assertInertia(fn (Assert $page) => $page->where($prefix.'.net_spending_minor', $spending)->where($prefix.'.income_minor', $income)->where($prefix.'.debt_payments_'.($direction === 'owed' ? 'made' : 'received').'_minor', '20000'));
    }
    $this->get('/breakdown?'.$query.'&focus='.($direction === 'owed' ? 'net_spending' : 'income'))->assertInertia(fn (Assert $page) => $page
        ->has('transaction_days', 1)->where('transaction_days.0.net_spending_minor.'.$currency, $spending)->where('transaction_days.0.income_minor.'.$currency, $income));
    if ($direction === 'owed') {
        $this->get('/breakdown?'.$query.'&category='.$category->id)->assertInertia(fn (Assert $page) => $page->where('category_groups.0.amount_minor.'.$currency, '3000')->where('category_groups.0.percentage.'.$currency, '100')->where('merchants.0.amount_minor.'.$currency, '3000')->where('days.4.net_spending_minor.'.$currency, '3000')->where('categorization.'.$currency.'.uncategorized_percentage', '0'));
        $this->get('/trends?'.$query)->assertInertia(fn (Assert $page) => $page->where('monthly_context.6.total_minor', '3000'));
    } else {
        $this->get('/breakdown?'.$query)->assertInertia(fn (Assert $page) => $page->where('transaction_days.0.transactions.0.income_source', 'interest_received')->where('income_source_options.0.value', 'interest_received')->where('income_source_options.0.used', true)->has('category_groups', 0));
    }
})->with([['owed', 'PEN'], ['receivable', 'USD']]);

test('monthly planning uses Lima calendar payments and excludes settled commitments by currency and direction', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 03:00:00', 'UTC'));
    $owner = User::factory()->create();
    $this->actingAs($owner);
    foreach ([['Outgoing', 'owed', 'PEN', '100000', '20000'], ['Incoming', 'receivable', 'PEN', '50000', '10000'], ['Dollars', 'owed', 'USD', '30000', '5000'], ['Settled', 'owed', 'PEN', '20000', '9000']] as [$name, $direction, $currency, $balance, $target]) {
        $this->post('/debts', ['name' => $name, 'counterparty' => 'Family', 'direction' => $direction, 'currency' => $currency, 'opening_balance_minor' => $balance, 'opened_on' => '2026-08-01', 'monthly_target_minor' => $target])->assertSessionHasNoErrors();
        $debts[$name] = collect($this->get('/debts')->inertiaProps('debts'))->firstWhere('name', $name)['id'];
    }
    foreach ([['Outgoing', '2026-08-31', '20000'], ['Outgoing', '2026-09-01', '30000'], ['Incoming', '2026-08-05', '5000'], ['Dollars', '2026-08-05', '2000'], ['Settled', '2026-08-05', '20000']] as [$name, $date, $amount]) {
        $this->post('/debts/'.$debts[$name].'/entries', ['kind' => 'repayment', 'occurred_on' => $date, 'amount_minor' => $amount, 'description' => 'Monthly payment'])->assertSessionHasNoErrors();
    }
    $this->get('/debts/'.$debts['Outgoing'])->assertInertia(fn (Assert $page) => $page->where('debt.monthly_target_minor', '20000')->where('debt.monthly_paid_minor', '20000')->where('debt.target_month', '2026-08')->where('debt.balance_minor', '50000'));
    $this->get('/?period=custom&date_from=2026-07-01&date_to=2026-07-31')->assertInertia(fn (Assert $page) => $page
        ->where('debt_summary.target_month', '2026-08')->where('debt_summary.PEN.owed_minor', '50000')->where('debt_summary.PEN.receivable_minor', '45000')
        ->where('debt_summary.PEN.outgoing_target_minor', '20000')->where('debt_summary.PEN.incoming_target_minor', '10000')
        ->where('debt_summary.PEN.payments_made_minor', '40000')->where('debt_summary.PEN.payments_received_minor', '5000')
        ->where('debt_summary.USD.owed_minor', '28000')->where('debt_summary.USD.outgoing_target_minor', '5000')->where('debt_summary.USD.payments_made_minor', '2000'));
    $this->put('/debts/'.$debts['Outgoing'], ['name' => 'Outgoing', 'counterparty' => 'Family', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '100000', 'opened_on' => '2026-08-01', 'monthly_target_minor' => null])->assertSessionHasNoErrors();
    $this->get('/debts/'.$debts['Outgoing'])->assertInertia(fn (Assert $page) => $page->where('debt.monthly_target_minor', null)->where('debt.monthly_paid_minor', '20000'));
    CarbonImmutable::setTestNow();
});

test('allocation corrections require exact replacements while real charges survive reassign unlink and voids', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    foreach (['First', 'Second'] as $name) {
        $this->post('/debts', ['name' => $name, 'counterparty' => 'Bank', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '100000', 'opened_on' => '2026-08-01']);
    }
    $debts = $this->get('/debts')->inertiaProps('debts');
    $id = $debts[0]['id'];
    foreach ([['principal_minor' => '17000', 'interest_minor' => '2999'], ['principal_minor' => '17000'], ['principal_minor' => '-1', 'interest_minor' => '20001'], ['principal' => '170.00', 'interest' => '30.001']] as $allocation) {
        $this->post("/debts/{$id}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-05', 'amount' => '200.00', 'description' => 'Invalid', 'interest_is_new' => true, ...$allocation])->assertSessionHasErrors();
        $this->get("/debts/{$id}")->assertInertia(fn (Assert $page) => $page->has('entries', 0)->where('debt.balance_minor', '100000'));
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 0));
    }
    $this->post("/debts/{$id}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-05', 'amount' => '200.00', 'description' => 'Payment', 'principal' => '170.00', 'interest' => '30.00', 'interest_is_new' => true])->assertSessionHasNoErrors();
    $payment = $this->get('/transactions')->inertiaProps('transactions.0.id');
    $edit = ['occurred_on' => '2026-08-05', 'kind' => 'debt', 'direction' => 'debit', 'currency' => 'PEN', 'amount_minor' => '20000', 'description' => 'Changed description'];
    $this->put("/transactions/{$payment}", $edit)->assertSessionHasNoErrors();
    $this->get("/debts/{$id}")->assertInertia(fn (Assert $page) => $page->has('entries', 2)->where('entries.0.interest_minor', '3000'));
    $this->put("/transactions/{$payment}", [...$edit, 'amount_minor' => '25000'])->assertSessionHasErrors('principal_minor');
    $this->put("/transactions/{$payment}", [...$edit, 'principal_minor' => '16000', 'interest_minor' => '4000', 'interest_is_new' => true])->assertSessionHasErrors('interest_is_new');
    $this->put("/transactions/{$payment}", [...$edit, 'debt_id' => $debts[1]['id'], 'principal_minor' => '16000', 'interest_minor' => '4000'])->assertSessionHasNoErrors();
    $this->get("/debts/{$id}")->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.kind', 'interest_charge')->where('debt.balance_minor', '103000'));
    $this->get('/debts/'.$debts[1]['id'])->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('debt.balance_minor', '80000'));
    $this->put("/transactions/{$payment}", [...$edit, 'kind' => 'spending', 'unlink_debt' => true])->assertSessionHasNoErrors();
    $this->get('/debts/'.$debts[1]['id'])->assertInertia(fn (Assert $page) => $page->has('entries', 0)->where('debt.balance_minor', '100000'));
    $this->post("/debts/{$id}/entries", ['kind' => 'adjustment', 'occurred_on' => '2026-08-06', 'amount_minor' => '-3000', 'reason' => 'Correct confirmed charge'])->assertSessionHasNoErrors();
    $this->get("/debts/{$id}")->assertInertia(fn (Assert $page) => $page->has('entries', 2)->where('debt.balance_minor', '100000'));
});

test('relinking a corrected payment cannot reconfirm its independent charge', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/debts', ['name' => 'Loan', 'counterparty' => 'Bank', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '100000', 'opened_on' => '2026-08-01']);
    $debt = $this->get('/debts')->inertiaProps('debts.0.id');
    $this->post("/debts/{$debt}/entries", ['kind' => 'repayment', 'occurred_on' => '2026-08-05', 'amount' => '200.00', 'description' => 'Payment', 'principal' => '170.00', 'interest' => '30.00', 'interest_is_new' => true])->assertSessionHasNoErrors();
    $payment = $this->get('/transactions')->inertiaProps('transactions.0.id');
    $this->put("/transactions/{$payment}", ['kind' => 'spending', 'direction' => 'debit', 'currency' => 'PEN', 'occurred_on' => '2026-08-05', 'amount' => '200.00', 'description' => 'Unlinked', 'unlink_debt' => true])->assertSessionHasNoErrors();
    $allocation = ['kind' => 'repayment', 'transaction_id' => $payment, 'principal' => '170.00', 'interest' => '30.00'];
    $this->post("/debts/{$debt}/entries", [...$allocation, 'interest_is_new' => true])->assertSessionHasErrors('interest_is_new');
    $this->post("/debts/{$debt}/entries", $allocation)->assertSessionHasNoErrors();
    $this->get("/debts/{$debt}")->assertInertia(fn (Assert $page) => $page->has('entries', 2)->where('debt.balance_minor', '83000'));
});
