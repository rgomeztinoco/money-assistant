<?php

use App\Actions\Categorization\SaveMerchantRule;
use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\MerchantRule;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\Models\YearlyPayment;
use App\Models\YearlyPaymentSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

function financialMcp(?string $token, string $method, array $params = [], array $headers = []): TestResponse
{
    Auth::forgetGuards();

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
        'params' => [...$params, '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ]],
    ], [...[
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => $token === null ? '' : 'Bearer '.$token,
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => $method,
        ...isset($params['name']) ? ['Mcp-Name' => $params['name']] : [],
    ], ...$headers]);
}

test('every MCP request authenticates a bearer token with read permission', function () {
    financialMcp(null, 'tools/list')->assertUnauthorized();
    financialMcp('malformed', 'tools/list')->assertUnauthorized();
    $owner = User::factory()->create();
    $denied = $owner->createToken('wrong ability', ['other:read']);
    financialMcp($denied->plainTextToken, 'tools/list')->assertForbidden();
    $token = $owner->createToken('reader', ['financial-data:read']);
    financialMcp($token->plainTextToken, 'tools/list')->assertOk()->assertHeaderMissing('MCP-Session-Id');
    $token->accessToken->delete();
    financialMcp($token->plainTextToken, 'tools/list')->assertUnauthorized();
});

test('independent modern requests read the owner taxonomy without initialize or a session', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $child = Category::factory()->for($owner, 'owner')->create(['name' => 'Lunch', 'parent_id' => $parent->id, 'archived_at' => '2026-09-01 00:00:00']);
    Category::factory()->create(['name' => 'Private other category']);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    financialMcp($token, 'tools/call', ['name' => 'list_categories', 'arguments' => (object) []])
        ->assertOk()->assertHeaderMissing('MCP-Session-Id')
        ->assertJsonPath('result.structuredContent.categories', [[
            'id' => $parent->id, 'parent_id' => null, 'name' => 'Food', 'archived_at' => null,
            'children' => [['id' => $child->id, 'parent_id' => $parent->id, 'name' => 'Lunch', 'archived_at' => '2026-09-01T00:00:00+00:00']],
        ]])->assertDontSee('transaction_count')->assertDontSee('user_id')->assertDontSee('Private other category');
    financialMcp($token, 'tools/list')->assertOk()->assertHeaderMissing('MCP-Session-Id');
});

test('transaction tools expose a precise curated representation and enforce owner scoping', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $category->id, 'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        'amount_minor' => 9007199254740993, 'description' => 'Market',
        'occurred_on' => '2026-09-19', 'currency' => 'PEN', 'kind' => 'spending', 'direction' => 'debit',
        'confirmed_at' => '2026-09-19 12:00:00', 'updated_at' => '2026-09-19 12:00:00',
        'instrument_label' => 'Private card', 'instrument_last_four' => '1234',
    ]);
    $other = Transaction::factory()->create();
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $expected = [
        'id' => $transaction->id, 'occurred_on' => '2026-09-19', 'amount_minor' => '9007199254740993',
        'currency' => 'PEN', 'kind' => 'spending', 'direction' => 'debit', 'income_source' => null, 'transfer_purpose' => null,
        'description' => 'Market', 'confirmed_at' => '2026-09-19T12:00:00+00:00', 'updated_at' => '2026-09-19T12:00:00+00:00', 'voided_at' => null,
        'category' => ['id' => $category->id, 'name' => 'Food', 'parent_id' => null, 'archived_at' => null],
        'category_assignment_provenance' => 'owner', 'review_state' => 'clear',
        'original_spending' => null, 'linked_refunds' => [], 'has_receipt_breakdown' => false,
        'category_allocations' => [['category' => ['id' => $category->id, 'name' => 'Food', 'parent_id' => null, 'archived_at' => null], 'amount_minor' => '9007199254740993']],
    ];
    $single = financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $transaction->id]])
        ->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent.transaction');
    expect($single)->toEqual($expected);
    financialMcp($token, 'tools/call', ['name' => 'list_transactions'])->assertOk()
        ->assertJsonCount(1, 'result.structuredContent.transactions')
        ->assertJsonPath('result.structuredContent.transactions.0', $single)
        ->assertDontSee('instrument_label')->assertDontSee('original_spending_id')->assertDontSee('provisional_fields');
    financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $other->id]])
        ->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Transaction not found.');
});

test('transaction filters use inclusive dates kind currency description and effective Category allocations', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $child = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries', 'parent_id' => $parent->id]);
    $fallback = Category::factory()->for($owner, 'owner')->create(['name' => 'Other']);
    $spending = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-09-10', 'currency' => 'PEN', 'description' => 'Corner MARKET', 'category_id' => $fallback->id,
    ]);
    $breakdown = ReceiptBreakdown::factory()->for($spending)->create();
    LineItem::factory()->for($breakdown)->create(['category_id' => $child->id, 'line_total_minor' => $spending->amount_minor]);
    $refund = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-09-11', 'currency' => 'USD', 'kind' => 'refund', 'description' => 'Refund', 'category_id' => $parent->id,
    ]);
    $voided = Transaction::factory()->for($owner, 'owner')->create(['voided_at' => now(), 'category_id' => $fallback->id]);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    foreach ([
        [['date_from' => '2026-09-10', 'date_to' => '2026-09-10'], [$spending->id]],
        [['currency' => 'USD'], [$refund->id]],
        [['kind' => 'refund'], [$refund->id]],
        [['search' => 'market'], [$spending->id]],
        [['category_id' => $child->id], [$spending->id]],
        [['category_id' => $parent->id], [$refund->id, $spending->id]],
        [['category_id' => $fallback->id], []],
        [['void_state' => 'voided'], [$voided->id]],
        [['date_from' => '2026-09-10', 'date_to' => '2026-09-10', 'currency' => 'PEN', 'kind' => 'spending', 'category_id' => $parent->id, 'search' => 'MARKET'], [$spending->id]],
    ] as [$filters, $ids]) {
        $rows = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => $filters])
            ->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent.transactions');
        expect(array_column($rows, 'id'))->toBe($ids);
    }
    $foreignCategory = Category::factory()->create();
    financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['category_id' => $foreignCategory->id]])
        ->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Category not found.');
});

test('fixed cursor pages preserve the snapshot and bind owner filters and sort order', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20T12:00:00Z'));
    $owner = User::factory()->create();
    $records = Transaction::factory()->for($owner, 'owner')->count(52)->create([
        'occurred_on' => '2026-09-01', 'updated_at' => now()->subHour(), 'currency' => 'PEN',
    ]);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $first = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['currency' => 'PEN']])
        ->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent');
    expect(array_column($first['transactions'], 'id'))->toBe($records->pluck('id')->reverse()->take(50)->values()->all())
        ->and($first['order'])->toBe('occurred_on_desc_id_desc')
        ->and($first['as_of'])->toBe('2026-09-20T12:00:00+00:00')
        ->and($first['checkpoint'])->toBeNull()->and($first['next_cursor'])->toBeString();
    $this->travel(1)->seconds();
    $records[0]->update(['description' => 'Edited during scan']);
    $added = Transaction::factory()->for($owner, 'owner')->create(['occurred_on' => '2026-08-01', 'currency' => 'PEN']);
    $second = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['currency' => 'PEN', 'cursor' => $first['next_cursor']]])
        ->assertOk()->json('result.structuredContent');
    expect(array_column($second['transactions'], 'id'))->toBe([$records[1]->id])
        ->and($second['as_of'])->toBe($first['as_of'])->and($second['checkpoint'])->toBe($first['as_of'])
        ->and($second['next_cursor'])->toBeNull();
    foreach ([['currency' => 'USD'], ['updated_since' => '2026-01-01T00:00:00Z']] as $changed) {
        financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => [...$changed, 'cursor' => $first['next_cursor']]])
            ->assertOk()->assertJsonPath('result.isError', true);
    }
    $next = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['updated_since' => $second['checkpoint']]])
        ->assertOk()->json('result.structuredContent');
    expect(array_column($next['transactions'], 'id'))->toBe([$records[0]->id, $added->id])
        ->and($next['order'])->toBe('updated_at_asc_id_asc');
    $repeated = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['updated_since' => $next['checkpoint']]])
        ->assertOk()->json('result.structuredContent');
    expect(array_column($repeated['transactions'], 'id'))->toBe([$records[0]->id, $added->id]);
});

test('tools advertise financial schemas and mutation annotations and support legacy negotiation', function () {
    $owner = User::factory()->create();
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $tools = financialMcp($token, 'tools/list')->assertOk()->json('result.tools');
    expect(array_column($tools, 'name'))->toBe(['list_transactions', 'get_transaction', 'list_categories', 'get_yearly_payment_plan', 'create_transaction', 'create_category', 'assign_transaction_category', 'set_transaction_category_split']);
    foreach ($tools as $tool) {
        $readOnly = in_array($tool['name'], ['list_transactions', 'get_transaction', 'list_categories', 'get_yearly_payment_plan'], true);
        $creates = in_array($tool['name'], ['create_transaction', 'create_category'], true);
        expect($tool['description'])->not->toBeEmpty()
            ->and($tool['inputSchema']['type'])->toBe('object')
            ->and($tool['outputSchema']['type'])->toBe('object')
            ->and($tool['annotations'])->toEqual(['readOnlyHint' => $readOnly, 'destructiveHint' => ! $readOnly && ! $creates, 'idempotentHint' => ! $creates, 'openWorldHint' => false]);
    }
    foreach (['income_source', 'transfer_purpose', 'category_assignment_provenance'] as $field) {
        expect($tools[1]['outputSchema']['properties']['transaction']['properties'][$field]['enum'])->toContain(null);
    }
    expect(array_keys($tools[0]['inputSchema']['properties']))->toEqualCanonicalizing([
        'date_from', 'date_to', 'updated_since', 'currency', 'kind', 'category_id', 'search', 'void_state', 'cursor',
    ]);
    $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'legacy-test', 'version' => '1.0'],
    ]])->assertOk()->assertHeaderMissing('MCP-Session-Id')
        ->assertJsonPath('result.protocolVersion', '2025-11-25')
        ->assertJsonPath('result.serverInfo.version', '2.0.0')
        ->assertJsonMissingPath('result.capabilities.resources')->assertJsonMissingPath('result.capabilities.prompts');
    Auth::forgetGuards();
    $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])
        ->assertOk()->assertHeaderMissing('MCP-Session-Id')->assertJsonCount(8, 'result.tools');
    financialMcp($token, 'tools/list', [], ['Mcp-Method' => 'tools/call'])->assertBadRequest();
});

test('each token has an independent allowance of 120 requests per minute', function () {
    $owner = User::factory()->create();
    $first = $owner->createToken('first', ['financial-data:read']);
    $second = $owner->createToken('second', ['financial-data:read']);
    for ($request = 0; $request < 120; $request++) {
        financialMcp($first->plainTextToken, 'tools/list')->assertOk();
    }
    financialMcp($first->plainTextToken, 'tools/list')->assertTooManyRequests()->assertHeader('Retry-After');
    financialMcp($second->plainTextToken, 'tools/list')->assertOk();
    expect($first->accessToken->fresh()->last_used_at)->not->toBeNull();
});

test('invalid financial filters and cursors fail without returning financial data', function (array $arguments) {
    $owner = User::factory()->create();
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => $arguments])
        ->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent');
})->with([
    [['date_from' => '2026-02-30']], [['date_from' => '2026-09-20', 'date_to' => '2026-09-01']],
    [['currency' => 'EUR']], [['kind' => 'expense']], [['void_state' => 'deleted']],
    [['updated_since' => 'yesterday']], [['category_id' => -1]], [['cursor' => 'tampered']],
]);

test('Receipt Breakdown allocations replace fallback and preserve grouped signed totals', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $fees = Category::factory()->for($owner, 'owner')->create(['name' => 'Fees']);
    $fallback = Category::factory()->for($owner, 'owner')->create(['name' => 'Fallback']);
    $transaction = Transaction::factory()->for($owner, 'owner')->refund()->create(['amount_minor' => 1000, 'category_id' => $fallback->id]);
    $breakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    foreach ([[$food->id, 700], [$food->id, 200], [$fees->id, -100], [null, 200]] as [$categoryId, $amount]) {
        LineItem::factory()->for($breakdown)->create(['category_id' => $categoryId, 'line_total_minor' => $amount, 'description' => 'PRIVATE LINE ITEM']);
    }
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $data = financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $transaction->id]])
        ->assertOk()->assertJsonPath('result.isError', false)->assertDontSee('PRIVATE LINE ITEM')->assertDontSee('line_items')->json('result.structuredContent.transaction');
    expect(array_column($data['category_allocations'], 'amount_minor'))->toBe(['900', '-100', '200'])
        ->and(array_map(fn ($allocation) => $allocation['category']['id'] ?? null, $data['category_allocations']))->toBe([$food->id, $fees->id, null])
        ->and($data['category']['id'])->toBe($fallback->id)->and($data['amount_minor'])->toBe('1000')
        ->and($data['review_state'])->toBe('outstanding');
});

test('incremental reads include changes to Receipt Breakdowns Categories and Refund relationships', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20T12:00:00Z'));
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $spending = Transaction::factory()->for($owner, 'owner')->pen()->create(['amount_minor' => 1000]);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $this->travel(1)->seconds();
    $checkpoint = now()->toIso8601String();
    $this->travel(1)->seconds();
    $this->actingAs($owner)->put(route('transactions.receipt_breakdowns.update', $spending), ['line_items' => [
        ['description' => 'Private groceries', 'line_total' => '10.00', 'category_id' => $category->id],
    ]])->assertSessionHasNoErrors();
    $read = fn () => financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['updated_since' => $checkpoint]])
        ->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent.transactions');
    expect(array_column($read(), 'id'))->toBe([$spending->id]);
    $this->travel(1)->seconds();
    $checkpoint = now()->toIso8601String();
    $this->travel(1)->seconds();
    $this->actingAs($owner)->put(route('categories.update', $category), ['name' => 'Renamed food', 'parent_id' => null])->assertSessionHasNoErrors();
    $rows = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['updated_since' => $checkpoint]])
        ->assertOk()->json('result.structuredContent.transactions');
    expect(array_column($rows, 'id'))->toBe([$spending->id])
        ->and($rows[0]['category_allocations'][0]['category']['name'])->toBe('Renamed food');
});

test('Income and Transfer have their own meanings and no allocations while Uncategorized Spending remains explicit', function () {
    $owner = User::factory()->create();
    $income = Transaction::factory()->for($owner, 'owner')->income()->create();
    $transfer = Transaction::factory()->for($owner, 'owner')->transfer()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->create(['amount_minor' => 750]);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    foreach ([$income, $transfer, $spending] as $transaction) {
        $row = financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $transaction->id]])
            ->assertOk()->json('result.structuredContent.transaction');
        expect($row['income_source'])->toBe($transaction->income_source?->value)
            ->and($row['transfer_purpose'])->toBe($transaction->transfer_purpose?->value)
            ->and($row['category_allocations'])->toBe($transaction->is($spending) ? [['category' => null, 'amount_minor' => '750']] : [])
            ->and($row['review_state'])->toBe($transaction->is($spending) ? 'outstanding' : 'clear');
    }
});

test('Refund relationships expose financial context in both directions and changes reach incremental readers', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20T12:00:00Z'));
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->pen()->create(['amount_minor' => 1000]);
    $refund = Transaction::factory()->for($owner, 'owner')->pen()->refund()->create(['amount_minor' => 300]);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $this->travel(1)->seconds();
    $checkpoint = now()->toIso8601String();
    $this->travel(1)->seconds();
    $refund->update(['original_spending_id' => $spending->id]);
    $rows = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['updated_since' => $checkpoint]])
        ->assertOk()->json('result.structuredContent.transactions');
    expect(array_column($rows, 'id'))->toBe([$spending->id, $refund->id])
        ->and($rows[0]['linked_refunds'][0]['id'])->toBe($refund->id)
        ->and($rows[0]['linked_refunds'][0]['amount_minor'])->toBe('300')
        ->and($rows[1]['original_spending']['id'])->toBe($spending->id)
        ->and($rows[1]['original_spending']['amount_minor'])->toBe('1000');
    $foreign = Transaction::factory()->refund()->create(['original_spending_id' => $spending->id]);
    financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $spending->id]])
        ->assertOk()->assertJsonCount(1, 'result.structuredContent.transaction.linked_refunds');
    $refund->update(['original_spending_id' => $foreign->id]);
    financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $refund->id]])
        ->assertOk()->assertJsonPath('result.structuredContent.transaction.original_spending', null);
});

test('unchanged scans cover every record exactly once in either fixed sort mode', function (bool $incremental) {
    $this->travelTo(CarbonImmutable::parse('2026-09-20T12:00:00Z'));
    $owner = User::factory()->create();
    $records = Transaction::factory()->for($owner, 'owner')->count(103)->create([
        'occurred_on' => '2026-09-01', 'updated_at' => now()->subHour(),
    ]);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $filters = $incremental ? ['updated_since' => '2026-09-20T11:00:00Z'] : [];
    $ids = [];
    $sizes = [];
    do {
        $page = financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => $filters])
            ->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent');
        $sizes[] = count($page['transactions']);
        $ids = [...$ids, ...array_column($page['transactions'], 'id')];
        $filters['cursor'] = $page['next_cursor'];
    } while ($page['next_cursor'] !== null);
    expect($sizes)->toBe([50, 50, 3])
        ->and($ids)->toBe(($incremental ? $records->pluck('id') : $records->pluck('id')->reverse())->values()->all())
        ->and($page['checkpoint'])->toBe('2026-09-20T12:00:00+00:00');
})->with([false, true]);

test('rotated and revoked tokens lose discovery and financial read access immediately', function () {
    $owner = User::factory()->create();
    $issued = $owner->createToken('reader', ['financial-data:read']);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $replacement = $this->postJson(route('agent-access.rotate', $issued->accessToken->id))
        ->assertOk()->json('plain_text_token');
    financialMcp($issued->plainTextToken, 'tools/list')->assertUnauthorized();
    financialMcp($issued->plainTextToken, 'tools/call', ['name' => 'list_transactions'])->assertUnauthorized();
    financialMcp($replacement, 'tools/list')->assertOk();
    $this->actingAs($owner)->deleteJson(route('agent-access.destroy', $issued->accessToken->id))->assertNoContent();
    financialMcp($replacement, 'tools/call', ['name' => 'list_categories'])->assertUnauthorized();
});

test('a browser session cannot substitute for the bearer credential', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
});

test('a cursor issued for another owner is rejected even with identical filters', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->count(51)->create();
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $cursor = financialMcp($token, 'tools/call', ['name' => 'list_transactions'])->assertOk()->json('result.structuredContent.next_cursor');
    $other = User::factory()->create()->createToken('reader', ['financial-data:read'])->plainTextToken;
    financialMcp($other, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['cursor' => $cursor]])
        ->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent');
});

test('a checkpoint retains a financial update committed after the scan', function () {
    config(['database.connections.mcp_writer' => config('database.connections.'.config('database.default'))]);
    $writer = DB::connection('mcp_writer');
    $owner = User::factory()->connection('mcp_writer')->create();
    $this->beforeApplicationDestroyed(function () use ($writer, $owner): void {
        $writer->table('users')->where('id', $owner->id)->delete();
        DB::purge('mcp_writer');
    });
    $transaction = Transaction::factory()->connection('mcp_writer')->for($owner, 'owner')->create([
        'description' => 'Before pending edit', 'updated_at' => now()->subHour(),
    ]);
    $token = $owner->setConnection(config('database.default'))->createToken('reader', ['financial-data:read'])->plainTextToken;
    $writer->beginTransaction();

    try {
        $this->travelTo(CarbonImmutable::parse($writer->scalar('SELECT clock_timestamp()')));
        $writer->table('transactions')->where('id', $transaction->id)->update([
            'description' => 'Committed after the scan', 'updated_at' => now(),
        ]);
        $this->travel(10)->seconds();
        $first = financialMcp($token, 'tools/call', ['name' => 'list_transactions'])
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.transactions.0.description', 'Before pending edit')
            ->json('result.structuredContent');
        $writer->commit();

        financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['updated_since' => $first['checkpoint']]])
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonCount(1, 'result.structuredContent.transactions')
            ->assertJsonPath('result.structuredContent.transactions.0.id', $transaction->id)
            ->assertJsonPath('result.structuredContent.transactions.0.description', 'Committed after the scan');
    } finally {
        $writer->rollBack();
    }
});

test('incremental filters preserve a fractional second lower bound', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20T12:00:00Z'));
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create(['updated_at' => '2026-09-20 11:00:00']);
    $included = Transaction::factory()->for($owner, 'owner')->create(['updated_at' => '2026-09-20 11:00:01']);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;

    financialMcp($token, 'tools/call', ['name' => 'list_transactions', 'arguments' => ['updated_since' => '2026-09-20T11:00:00.500000Z']])
        ->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonCount(1, 'result.structuredContent.transactions')
        ->assertJsonPath('result.structuredContent.transactions.0.id', $included->id);
});

test('the yearly-payment MCP tool returns the same exact owner plan as the web without mutating records', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 02:00:00', 'UTC'));
    $owner = User::factory()->create();
    $payment = YearlyPayment::factory()->for($owner, 'owner')->create(['name' => 'Insurance', 'amount_minor' => 240000, 'cushion_minor' => 120, 'expected_due_on' => '2026-10-08']);
    YearlyPayment::factory()->for($owner, 'owner')->create(['name' => 'Course', 'amount_minor' => 100, 'cushion_minor' => 1, 'currency' => 'USD']);
    YearlyPayment::factory()->for($owner, 'owner')->paused()->create(['name' => 'Paused checkup', 'expected_due_on' => '2026-10-08']);
    YearlyPaymentSetting::factory()->for($owner, 'owner')->create(['pen_per_usd' => '3.7500']);
    $other = User::factory()->create();
    YearlyPayment::factory()->for($other, 'owner')->create(['name' => 'Private other plan']);
    YearlyPaymentSetting::factory()->for($other, 'owner')->create(['pen_per_usd' => '999']);
    $before = YearlyPayment::query()->get()->toArray();
    $settings = YearlyPaymentSetting::query()->get()->toArray();
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;

    $data = financialMcp($token, 'tools/call', ['name' => 'get_yearly_payment_plan', 'arguments' => (object) []])
        ->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonCount(3, 'result.structuredContent.commitments')
        ->assertJsonPath('result.structuredContent.commitments.0.target_minor', '240120')
        ->assertJsonPath('result.structuredContent.commitments.2.is_active', false)
        ->assertJsonPath('result.structuredContent.native_targets.0.annual_target_minor', '240120')
        ->assertJsonPath('result.structuredContent.native_targets.0.monthly_recommendation_minor', '20010')
        ->assertJsonPath('result.structuredContent.native_targets.1.annual_target_minor', '101')
        ->assertJsonPath('result.structuredContent.combined_estimate.currency', 'PEN')
        ->assertJsonPath('result.structuredContent.combined_estimate.annual_target_minor', '240499')
        ->assertJsonPath('result.structuredContent.combined_estimate.monthly_recommendation_minor', '20042')
        ->assertJsonPath('result.structuredContent.planning_rate', ['pen_per_usd' => '3.7500', 'direction' => 'PEN per USD', 'source' => 'manual'])
        ->assertJsonCount(1, 'result.structuredContent.upcoming_commitments')
        ->assertJsonPath('result.structuredContent.upcoming_commitments.0.id', $payment->id)
        ->assertJsonPath('result.structuredContent.calculation_date', '2026-10-08')
        ->assertJsonPath('result.structuredContent.timezone', 'America/Lima')
        ->assertDontSee('Private other plan')->assertDontSee('user_id')
        ->json('result.structuredContent');
    $this->actingAs($owner)->get(route('yearly_payments.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('plan', $data));
    financialMcp($token, 'tools/call', ['name' => 'get_yearly_payment_plan'])->assertJsonPath('result.structuredContent', $data);

    expect(YearlyPayment::query()->get()->toArray())->toBe($before);
    expect(YearlyPaymentSetting::query()->get()->toArray())->toBe($settings);
    $this->assertDatabaseCount('transactions', 0);
});

test('MCP reports missing conversion assumptions and enforces bearer read access for the planner', function () {
    $owner = User::factory()->create();
    YearlyPayment::factory()->for($owner, 'owner')->create(['currency' => 'USD', 'amount_minor' => 9007199254740993]);
    $token = $owner->createToken('reader', ['financial-data:read'])->plainTextToken;
    $denied = $owner->createToken('writer', ['financial-data:write'])->plainTextToken;

    financialMcp(null, 'tools/call', ['name' => 'get_yearly_payment_plan'])->assertUnauthorized();
    financialMcp($denied, 'tools/call', ['name' => 'get_yearly_payment_plan'])->assertForbidden();
    financialMcp($token, 'tools/call', ['name' => 'get_yearly_payment_plan'])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.native_targets.1.annual_target_minor', '9007199254740993')
        ->assertJsonPath('result.structuredContent.combined_estimate', [
            'status' => 'unavailable', 'currency' => 'PEN', 'annual_target_minor' => null,
            'monthly_recommendation_minor' => null, 'unavailable_reason' => 'Enter a planning exchange rate in PEN per USD to include USD commitments.',
        ])
        ->assertJsonPath('result.structuredContent.planning_rate.pen_per_usd', null)
        ->assertJsonPath('result.structuredContent.assumptions.monthly_rounding', 'Divide the aggregate annual target by 12 and round upward to the next minor unit.')
        ->assertJsonPath('result.structuredContent.assumptions.planning_only', 'Planning targets do not represent money saved, bills paid, or money safe to spend today.');
    $tools = financialMcp($token, 'tools/list')->json('result.tools');
    expect($tools[3]['inputSchema']['properties'])->toBeEmpty();
});

test('an existing agent token creates confirmed Transactions and reads the saved financial representation', function (array $classification, string $direction) {
    $owner = User::factory()->create();
    $token = $owner->createToken('existing agent', ['financial-data:read'])->plainTextToken;
    $this->travel(1)->days();
    $saved = financialMcp($token, 'tools/call', ['name' => 'create_transaction', 'arguments' => [
        'occurred_on' => '2026-10-07', 'amount_minor' => '9007199254740993', 'description' => 'Confirmed movement', ...$classification,
    ]])->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent.transaction');
    expect($saved['amount_minor'])->toBe('9007199254740993')
        ->and($saved['currency'])->toBe('PEN')->and($saved['direction'])->toBe($direction)
        ->and($saved['kind'])->toBe($classification['kind'])->and($saved['confirmed_at'])->not->toBeNull();
    financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $saved['id']]])
        ->assertOk()->assertJsonPath('result.structuredContent.transaction', $saved);
    financialMcp($token, 'tools/call', ['name' => 'list_transactions'])
        ->assertOk()->assertJsonPath('result.structuredContent.transactions.0.id', $saved['id']);
})->with([
    [['kind' => 'spending'], 'debit'],
    [['kind' => 'refund', 'original_spending_id' => null], 'credit'],
    [['kind' => 'income', 'income_source' => 'salary'], 'credit'],
    [['kind' => 'transfer', 'transfer_purpose' => 'savings', 'direction' => 'credit'], 'credit'],
]);

test('MCP creation rejects invalid amounts classifications dates and unsupported fields without recording a movement', function (array $changes) {
    $owner = User::factory()->create();
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    financialMcp($token, 'tools/call', ['name' => 'create_transaction', 'arguments' => [
        'occurred_on' => '2026-10-07', 'amount_minor' => '1000', 'kind' => 'spending', 'description' => 'Movement', ...$changes,
    ]])->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent');
    expect($owner->transactions()->count())->toBe(0);
})->with([
    [['amount_minor' => '0']], [['amount_minor' => '-1']], [['amount_minor' => '1.5']],
    [['amount_minor' => '1e3']], [['amount_minor' => 1000]], [['amount_minor' => '9223372036854775808']],
    [['occurred_on' => 'yesterday']], [['occurred_on' => '2026-02-30']], [['currency' => 'EUR']],
    [['description' => '   ']], [['kind' => 'income']], [['kind' => 'income', 'income_source' => 'unknown']],
    [['kind' => 'transfer', 'transfer_purpose' => 'savings']], [['kind' => 'transfer', 'direction' => 'debit']],
    [['instrument_label' => 'private']], [['user_id' => 1]], [['amount' => '10.00']],
    [['kind' => 'unsupported']], [['direction' => 'unknown']],
]);

test('MCP creation preserves exact supported maximum and explicit Currency and Direction', function () {
    $owner = User::factory()->create();
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    financialMcp($token, 'tools/call', ['name' => 'create_transaction', 'arguments' => [
        'occurred_on' => '2026-10-07', 'amount_minor' => '9223372036854775807', 'currency' => 'USD',
        'kind' => 'spending', 'direction' => 'credit', 'description' => 'Exact maximum',
    ]])->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.transaction.amount_minor', '9223372036854775807')
        ->assertJsonPath('result.structuredContent.transaction.currency', 'USD')
        ->assertJsonPath('result.structuredContent.transaction.direction', 'credit');
});

test('MCP creation distinguishes omitted and cleared Categories and validates owner assignment', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $foreign = Category::factory()->create();
    $archived = Category::factory()->for($owner, 'owner')->create(['archived_at' => now()]);
    app(SaveMerchantRule::class)->handle($owner, 'Corner Market', $category->id, null, null, true);
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    $create = fn (array $changes) => financialMcp($token, 'tools/call', ['name' => 'create_transaction', 'arguments' => [
        'occurred_on' => '2026-10-07', 'amount_minor' => '1000', 'kind' => 'spending', 'description' => 'Corner Market', ...$changes,
    ]]);
    $create([])->assertOk()->assertJsonPath('result.structuredContent.transaction.category.id', $category->id)
        ->assertJsonPath('result.structuredContent.transaction.category_assignment_provenance', 'merchant_rule');
    $create(['category_id' => null])->assertOk()->assertJsonPath('result.structuredContent.transaction.category', null);
    $create(['category_id' => $category->id])->assertOk()->assertJsonPath('result.structuredContent.transaction.category_assignment_provenance', 'owner');
    foreach ([$foreign->id, $archived->id] as $id) {
        $create(['category_id' => $id])->assertOk()->assertJsonPath('result.isError', true);
    }
    $create(['kind' => 'income', 'income_source' => 'salary', 'category_id' => $category->id])->assertOk()->assertJsonPath('result.isError', true);
    expect($owner->transactions()->count())->toBe(3);
});

test('MCP Refund creation links atomically and returns the existing relationship review', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()->for($owner, 'owner')->pen()->create(['amount_minor' => 500]);
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    $create = fn (int $originalId) => financialMcp($token, 'tools/call', ['name' => 'create_transaction', 'arguments' => [
        'occurred_on' => '2026-10-07', 'amount_minor' => '1000', 'kind' => 'refund', 'description' => 'Reimbursement', 'original_spending_id' => $originalId,
    ]]);
    $refund = $create($spending->id)->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.transaction.original_spending.id', $spending->id)
        ->assertJsonPath('result.structuredContent.transaction.review_state', 'outstanding')->json('result.structuredContent.transaction');
    financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $spending->id]])
        ->assertOk()->assertJsonPath('result.structuredContent.transaction.linked_refunds.0.id', $refund['id']);
    foreach ([
        Transaction::factory()->create(['currency' => 'PEN']),
        Transaction::factory()->for($owner, 'owner')->usd()->create(),
        Transaction::factory()->for($owner, 'owner')->pen()->create(['voided_at' => now()]),
        Transaction::factory()->for($owner, 'owner')->income()->pen()->create(),
    ] as $invalid) {
        $before = $owner->transactions()->count();
        $create($invalid->id)->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent');
        expect($owner->transactions()->count())->toBe($before);
    }
});

test('MCP creates a normalized two-level Category and uses its stable identity for a Transaction', function () {
    $owner = User::factory()->create();
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    $parent = financialMcp($token, 'tools/call', ['name' => 'create_category', 'arguments' => ['name' => '  Food  ']])
        ->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.category.name', 'Food')
        ->assertDontSee('user_id')->json('result.structuredContent.category');
    $child = financialMcp($token, 'tools/call', ['name' => 'create_category', 'arguments' => ['name' => '  Eating   out ', 'parent_id' => $parent['id']]])
        ->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.category.name', 'Eating out')
        ->assertJsonPath('result.structuredContent.category.parent_id', $parent['id'])->json('result.structuredContent.category');
    financialMcp($token, 'tools/call', ['name' => 'list_categories'])
        ->assertOk()->assertJsonPath('result.structuredContent.categories.0.children.0.id', $child['id']);
    financialMcp($token, 'tools/call', ['name' => 'create_transaction', 'arguments' => [
        'occurred_on' => '2026-10-07', 'amount_minor' => '1000', 'kind' => 'spending', 'description' => 'Lunch', 'category_id' => $child['id'],
    ]])->assertOk()->assertJsonPath('result.structuredContent.transaction.category.id', $child['id']);
});

test('MCP direct Category assignment replaces or clears fallback while preserving effective receipt allocations', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $replacement = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->pen()->create(['category_id' => $category->id, 'amount_minor' => 1000]);
    $breakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    $item = LineItem::factory()->for($breakdown)->create(['category_id' => $category->id, 'line_total_minor' => 1000])->fresh();
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    foreach ([$replacement->id, null] as $id) {
        financialMcp($token, 'tools/call', ['name' => 'assign_transaction_category', 'arguments' => ['id' => $transaction->id, 'category_id' => $id]])
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.transaction.category', $id === null ? null : ['id' => $replacement->id, 'name' => $replacement->name, 'parent_id' => null, 'archived_at' => null])
            ->assertJsonPath('result.structuredContent.transaction.category_assignment_provenance', $id === null ? null : 'owner')
            ->assertJsonPath('result.structuredContent.transaction.has_receipt_breakdown', true)
            ->assertJsonPath('result.structuredContent.transaction.category_allocations.0.category.id', $category->id);
    }
    expect($item->fresh()->getAttributes())->toEqual($item->getAttributes());
    expect(MerchantRule::query()->whereBelongsTo($owner, 'owner')->count())->toBe(0);
});

test('MCP category splits reconcile signed allocations and replace receipt detail without changing the movement', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create();
    $fees = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create(['amount_minor' => 1000, 'category_id' => $fees->id]);
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    $set = fn (array $allocations) => financialMcp($token, 'tools/call', ['name' => 'set_transaction_category_split', 'arguments' => ['id' => $transaction->id, 'allocations' => $allocations]]);
    $set([['amount_minor' => '1000', 'category_id' => $food->id]])->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.transaction.has_receipt_breakdown', true)
        ->assertJsonPath('result.structuredContent.transaction.category_allocations.0.category.id', $food->id);
    $transaction->receiptBreakdown->lineItems()->first()->update(['description' => 'Private receipt detail', 'quantity' => '2', 'unit_price_minor' => 500]);
    $saved = $set([
        ['amount_minor' => '900', 'category_id' => $food->id],
        ['amount_minor' => '-100', 'category_id' => $fees->id],
        ['amount_minor' => '200', 'category_id' => null],
    ])->assertOk()->assertJsonPath('result.isError', false)->assertDontSee('Private receipt detail')
        ->assertJsonPath('result.structuredContent.transaction.category.id', $fees->id)
        ->assertJsonPath('result.structuredContent.transaction.amount_minor', '1000')->json('result.structuredContent.transaction');
    expect(array_column($saved['category_allocations'], 'amount_minor'))->toBe(['900', '-100', '200']);
    expect($transaction->fresh()->amount_minor)->toBe(1000);
    $items = $transaction->receiptBreakdown->lineItems()->get();
    expect($items)->toHaveCount(3);
    foreach ($items as $item) {
        expect($item->quantity)->toBeNull()->and($item->unit_price_minor)->toBeNull()->and($item->description)->not->toBe('Private receipt detail');
    }
    financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $transaction->id]])
        ->assertOk()->assertJsonPath('result.structuredContent.transaction', $saved);
    $set([['amount_minor' => '999', 'category_id' => $food->id]])->assertOk()->assertJsonPath('result.isError', true);
    financialMcp($token, 'tools/call', ['name' => 'get_transaction', 'arguments' => ['id' => $transaction->id]])
        ->assertOk()->assertJsonPath('result.structuredContent.transaction', $saved);
    expect($transaction->receiptBreakdown->lineItems()->get()->map->getAttributes()->all())->toEqual($items->map->getAttributes()->all());
});

test('MCP Category creation rejects conflicts invalid names and nonassignable parents without editing the taxonomy', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $child = Category::factory()->for($owner, 'owner')->create(['parent_id' => $parent->id]);
    $archived = Category::factory()->for($owner, 'owner')->create(['archived_at' => now()]);
    $foreign = Category::factory()->create(['name' => 'Private parent']);
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    foreach ([['name' => '  fOoD  '], ['name' => '   '], ['name' => str_repeat('x', 256)],
        ['name' => 'Child', 'parent_id' => $child->id], ['name' => 'Child', 'parent_id' => $archived->id],
        ['name' => 'Child', 'parent_id' => $foreign->id], ['name' => 'New', 'archived_at' => now()->toIso8601String()],
    ] as $arguments) {
        financialMcp($token, 'tools/call', ['name' => 'create_category', 'arguments' => $arguments])
            ->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent')->assertDontSee('Private parent');
    }
    expect($owner->categories()->count())->toBe(3);
    financialMcp($token, 'tools/call', ['name' => 'create_category', 'arguments' => ['name' => 'Food', 'parent_id' => $parent->id]])
        ->assertOk()->assertJsonPath('result.isError', false);
    financialMcp($token, 'tools/call', ['name' => 'create_category', 'arguments' => ['name' => $archived->name]])
        ->assertOk()->assertJsonPath('result.isError', false);
});

test('MCP direct Category assignment rejects missing Categories and foreign archived or incompatible records', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $foreign = Category::factory()->create();
    $archived = Category::factory()->for($owner, 'owner')->create(['archived_at' => now()]);
    $childOfArchived = Category::factory()->for($owner, 'owner')->create(['parent_id' => $archived->id]);
    $transaction = Transaction::factory()->for($owner, 'owner')->create(['category_id' => $category->id]);
    $foreignTransaction = Transaction::factory()->create(['description' => 'Private movement']);
    $income = Transaction::factory()->for($owner, 'owner')->income()->create();
    $transfer = Transaction::factory()->for($owner, 'owner')->transfer()->create();
    $voided = Transaction::factory()->for($owner, 'owner')->create(['voided_at' => now()]);
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    foreach ([['id' => $transaction->id], ['id' => $transaction->id, 'category_id' => $foreign->id],
        ['id' => $transaction->id, 'category_id' => $archived->id], ['id' => $transaction->id, 'category_id' => $childOfArchived->id],
        ['id' => $foreignTransaction->id, 'category_id' => $category->id], ['id' => $income->id, 'category_id' => null],
        ['id' => $transfer->id, 'category_id' => $category->id], ['id' => $voided->id, 'category_id' => $category->id],
    ] as $arguments) {
        financialMcp($token, 'tools/call', ['name' => 'assign_transaction_category', 'arguments' => $arguments])
            ->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent')->assertDontSee('Private movement');
    }
    expect($transaction->fresh()->category_id)->toBe($category->id);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->create();
    financialMcp($token, 'tools/call', ['name' => 'assign_transaction_category', 'arguments' => ['id' => $refund->id, 'category_id' => $category->id]])
        ->assertOk()->assertJsonPath('result.structuredContent.transaction.category.id', $category->id);
});

test('MCP invalid split replacements preserve the current receipt and its detail', function (array $allocations) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->pen()->create(['amount_minor' => 1000]);
    $breakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    $item = LineItem::factory()->for($breakdown)->create(['description' => 'Original detail', 'line_total_minor' => 1000])->fresh();
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    financialMcp($token, 'tools/call', ['name' => 'set_transaction_category_split', 'arguments' => ['id' => $transaction->id, 'allocations' => $allocations]])
        ->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent');
    expect($item->fresh()->getAttributes())->toEqual($item->getAttributes());
    expect($breakdown->lineItems()->count())->toBe(1);
    expect($transaction->fresh()->amount_minor)->toBe(1000);
})->with([
    [[]], [[['amount_minor' => '0', 'category_id' => null]]], [[['amount_minor' => '1.5', 'category_id' => null]]],
    [[['amount_minor' => '9007199254740992', 'category_id' => null]]], [[['amount_minor' => '-9007199254740992', 'category_id' => null]]],
    [[['amount_minor' => '1000']]], [[['amount_minor' => 1000, 'category_id' => null]]],
    [[['amount_minor' => '1000', 'category_id' => null, 'description' => 'Not accepted']]],
    [array_fill(0, 201, ['amount_minor' => '1', 'category_id' => null])],
]);

test('MCP split replacement rejects foreign archived Categories and inactive or incompatible Transactions', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create(['amount_minor' => 1000]);
    $breakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    $item = LineItem::factory()->for($breakdown)->create(['line_total_minor' => 1000])->fresh();
    $foreignCategory = Category::factory()->create();
    $archived = Category::factory()->for($owner, 'owner')->create(['archived_at' => now()]);
    $childOfArchived = Category::factory()->for($owner, 'owner')->create(['parent_id' => $archived->id]);
    $token = $owner->createToken('agent', ['financial-data:read'])->plainTextToken;
    foreach ([$foreignCategory, $archived, $childOfArchived] as $category) {
        financialMcp($token, 'tools/call', ['name' => 'set_transaction_category_split', 'arguments' => [
            'id' => $transaction->id, 'allocations' => [['amount_minor' => '1000', 'category_id' => $category->id]],
        ]])->assertOk()->assertJsonPath('result.isError', true);
        expect($item->fresh()->getAttributes())->toEqual($item->getAttributes());
    }
    foreach ([
        Transaction::factory()->create(['amount_minor' => 1000, 'description' => 'Private movement']),
        Transaction::factory()->for($owner, 'owner')->income()->create(['amount_minor' => 1000]),
        Transaction::factory()->for($owner, 'owner')->transfer()->create(['amount_minor' => 1000]),
        Transaction::factory()->for($owner, 'owner')->create(['amount_minor' => 1000, 'voided_at' => now()]),
    ] as $invalid) {
        financialMcp($token, 'tools/call', ['name' => 'set_transaction_category_split', 'arguments' => [
            'id' => $invalid->id, 'allocations' => [['amount_minor' => '1000', 'category_id' => null]],
        ]])->assertOk()->assertJsonPath('result.isError', true)->assertDontSee('Private movement');
        expect($invalid->receiptBreakdown()->exists())->toBeFalse();
    }
});
