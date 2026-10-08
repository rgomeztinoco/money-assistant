<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Jobs\ProcessGmailMessage;
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
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-08-06', 'amount_minor' => '4002', 'currency' => 'PEN', 'kind' => 'debt', 'direction' => 'debit', 'description' => 'Corrected payment'])->assertSessionHasNoErrors();
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '5998')->where('entries.0.amount_minor', '4002')->where('entries.0.occurred_on', '2026-08-06'));
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-08-06', 'amount_minor' => '4002', 'currency' => 'USD', 'kind' => 'debt', 'direction' => 'debit', 'description' => 'Invalid correction'])->assertSessionHasErrors('currency');
    $this->put(route('transactions.update', $payment), ['occurred_on' => '2026-07-31', 'amount_minor' => '4002', 'currency' => 'PEN', 'kind' => 'debt', 'direction' => 'debit', 'description' => 'Before baseline'])->assertSessionHasErrors('occurred_on');
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
    $this->actingAs($owner)->post('/debts', ['name' => 'Loan', 'counterparty' => 'Mother', 'direction' => 'owed', 'currency' => 'PEN', 'opening_balance_minor' => '10000', 'opened_on' => '2026-02-01']);
    $debtId = $this->get('/debts')->inertiaProps('debts.0.id');
    $payment = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create(['occurred_on' => '2026-02-04', 'amount_minor' => 1000, 'description' => 'Pago YAPE a 123456', 'instrument_label' => 'BCP Cuenta Digital']);
    $this->post("/debts/{$debtId}/entries", ['kind' => 'repayment', 'transaction_id' => $payment->id])->assertSessionHasNoErrors();
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
    $this->get("/debts/{$debtId}")->assertInertia(fn (Assert $page) => $page->where('debt.balance_minor', '9000')->has('entries', 1)->where('entries.0.transaction_id', $payment->id));
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
