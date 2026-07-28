<?php

use App\CategoryAssignmentProvenance;
use App\Contracts\OpenClawHook;
use App\Currency;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\OpenClawAuditEvent;
use App\Models\OpenClawConfirmationGrant;
use App\Models\OpenClawPendingOperation;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $keyPair = sodium_crypto_sign_keypair();

    $this->openClawPrivateKey = sodium_crypto_sign_secretkey($keyPair);

    config([
        'services.openclaw.capability.key_id' => 'openclaw-service-2026-07',
        'services.openclaw.capability.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
        'services.openclaw.capability.agent_id' => 'money-assistant',
        'services.openclaw.capability.account_id' => 'money-assistant-owner',
        'services.openclaw.capability.conversation_id' => 'telegram-owner-123',
        'services.openclaw.capability.owner_sender_id' => 'telegram-owner-123',
        'services.openclaw.capability.rate_limit_per_minute' => 60,
        'services.openclaw.hook.token' => 'outbound-hook-token',
        'services.openclaw.hook.url' => 'http://127.0.0.1:18789/hooks/money-assistant',
    ]);
    RateLimiter::for('openclaw-ingress', fn (): Limit => Limit::perMinute(120));

    $this->callOpenClaw = function (
        array $payload,
        ?string $nonce = null,
        ?string $timestamp = null,
        string $method = 'POST',
        string $path = '/api/openclaw/v1/transport',
        array $signatureOverrides = [],
    ): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= (string) now()->getTimestamp();
        $nonce ??= (string) Str::uuid();
        $signature = sodium_crypto_sign_detached(implode("\n", [
            $signatureOverrides['timestamp'] ?? $timestamp,
            $signatureOverrides['nonce'] ?? $nonce,
            $signatureOverrides['method'] ?? $method,
            $signatureOverrides['path'] ?? $path,
            hash('sha256', $signatureOverrides['body'] ?? $body),
        ]), $this->openClawPrivateKey);

        return $this->call(
            $method,
            $path,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_MONEY_ASSISTANT_KEY_ID' => 'openclaw-service-2026-07',
                'HTTP_X_MONEY_ASSISTANT_TIMESTAMP' => $timestamp,
                'HTTP_X_MONEY_ASSISTANT_NONCE' => $nonce,
                'HTTP_X_MONEY_ASSISTANT_SIGNATURE' => base64_encode($signature),
            ],
            content: $body,
        );
    };

    $this->validPayload = fn (int $transactionId): array => [
        'schema_version' => 1,
        'capability' => 'transaction.read',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-456',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'transaction_id' => $transactionId,
        ],
    ];

    $this->validManualTransactionPreparation = fn (): array => [
        'schema_version' => 1,
        'capability' => 'transaction.manual.prepare',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-prepare',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf372',
            'occurred_on' => '2026-07-24',
            'amount_minor' => 12345,
            'currency' => 'USD',
            'kind' => 'purchase',
            'merchant_description' => '  Neighborhood   market  ',
        ],
    ];

    $this->validManualTransactionConfirmation = fn (array $operation): array => [
        'schema_version' => 1,
        'capability' => 'transaction.manual.confirm',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-approve',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf374',
            'pending_operation_id' => $operation['id'],
            'pending_operation_revision' => $operation['revision'],
            'payload_digest' => $operation['payload_digest'],
        ],
    ];

    $this->validCategoryRead = fn (): array => [
        'schema_version' => 1,
        'capability' => 'category.read',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-category-read',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'page' => 1,
            'per_page' => 1,
        ],
    ];

    $this->validCategoryCreationPreparation = fn (): array => [
        'schema_version' => 1,
        'capability' => 'category.mutation.prepare',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-category-prepare',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf380',
            'operation' => 'create',
            'name' => '  Family   Care ',
            'parent_id' => null,
            'description' => 'Shared family costs',
            'examples' => ['Childcare'],
        ],
    ];

    $this->validCategoryAssignmentPreparation = fn (Transaction $transaction, Category $category): array => [
        'schema_version' => 1,
        'capability' => 'category.mutation.prepare',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-category-assign-prepare',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf381',
            'operation' => 'assign_transaction',
            'transaction_id' => $transaction->id,
            'expected_revision' => $transaction->revision,
            'category_id' => $category->id,
        ],
    ];

    $this->validCategoryConfirmation = fn (array $operation): array => [
        'schema_version' => 1,
        'capability' => 'category.mutation.confirm',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-message-category-approve',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf382',
            'pending_operation_id' => $operation['id'],
            'pending_operation_revision' => $operation['revision'],
            'payload_digest' => $operation['payload_digest'],
        ],
    ];
});

test('OpenClaw reads the bounded Category taxonomy without inventing Uncategorized', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $groceries = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Groceries',
    ]);

    ($this->callOpenClaw)(($this->validCategoryRead)())
        ->assertSuccessful()
        ->assertExactJson([
            'schema_version' => 1,
            'categories' => [[
                'id' => $food->id,
                'parent_id' => null,
                'name' => 'Food',
                'description' => null,
                'examples' => [],
                'revision' => 1,
                'retired_at' => null,
            ]],
            'pagination' => [
                'page' => 1,
                'per_page' => 1,
                'total' => 2,
                'next_page' => 2,
            ],
        ]);

    expect(Category::query()->where('name', 'Uncategorized')->exists())->toBeFalse()
        ->and(OpenClawAuditEvent::query()->sole()->resource_type)->toBe('category_taxonomy');
});

test('OpenClaw confirms Category creation through the shared Categorization Action', function () {
    User::factory()->create();

    $operation = ($this->callOpenClaw)(($this->validCategoryCreationPreparation)())
        ->assertSuccessful()
        ->assertJsonPath(
            'pending_operation.effect_summary',
            'Create the top-level Category "Family Care". Guidance description: "Shared family costs". Guidance examples: ["Childcare"].',
        )
        ->json('pending_operation');

    expect(Category::query()->count())->toBe(0);

    ($this->callOpenClaw)(($this->validCategoryConfirmation)($operation))
        ->assertSuccessful()
        ->assertJsonPath('mutation.operation', 'create')
        ->assertJsonPath('mutation.resource_type', 'category')
        ->assertJsonPath('mutation.revision', 1);

    expect(Category::query()->sole())
        ->name->toBe('Family Care')
        ->description->toBe('Shared family costs')
        ->examples->toBe(['Childcare']);
});

test('OpenClaw confirms owner Category assignment and rejects changed Transaction state', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $preparation = ($this->validCategoryAssignmentPreparation)($transaction, $category);
    $operation = ($this->callOpenClaw)($preparation)
        ->assertSuccessful()
        ->assertJsonPath(
            'pending_operation.effect_summary',
            "Assign the Category \"Groceries\" to Transaction #{$transaction->id} at revision 1.",
        )
        ->json('pending_operation');

    $transaction->increment('revision');

    ($this->callOpenClaw)(($this->validCategoryConfirmation)($operation))
        ->assertConflict();

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))->toBe('stale_revision');

    $freshPreparation = ($this->validCategoryAssignmentPreparation)($transaction->fresh(), $category);
    $freshPreparation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf383';
    $freshPreparation['interaction']['message_id'] = 'telegram-owner-message-category-assign-fresh';
    $freshOperation = ($this->callOpenClaw)($freshPreparation)->assertSuccessful()->json('pending_operation');
    $confirmation = ($this->validCategoryConfirmation)($freshOperation);
    $confirmation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf384';

    ($this->callOpenClaw)($confirmation)->assertSuccessful();

    expect($transaction->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->revision->toBe(3);

    $readPayload = ($this->validPayload)($transaction->id);
    $readPayload['interaction']['message_id'] = 'telegram-owner-message-category-read';

    ($this->callOpenClaw)($readPayload)
        ->assertSuccessful()
        ->assertJsonPath('transaction.category.id', $category->id)
        ->assertJsonPath('transaction.category.provenance.source', 'owner')
        ->assertJsonPath('transaction.category.provenance.owner.id', $owner->id)
        ->assertJsonPath('transaction.category.provenance.owner.name', $owner->name);
});

test('OpenClaw edits and confirms the same expected Receipt Breakdown revision as the web', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->recycle($owner)->purchase()->pen()->create([
        'amount_minor' => 2500,
    ]);
    $draft = ReceiptBreakdown::factory()->recycle($owner)->for($transaction)->draft()->create();
    $firstLineItem = LineItem::factory()->for($draft)->create(['line_total_minor' => 1000]);
    $secondLineItem = LineItem::factory()->for($draft)->create(['line_total_minor' => 1500]);
    $lineItems = [
        [
            'id' => $firstLineItem->line_item_id,
            'description' => 'Coffee',
            'line_total_minor' => 1000,
            'category_id' => $category->id,
        ],
        [
            'id' => $secondLineItem->line_item_id,
            'description' => 'Fruit',
            'line_total_minor' => 1500,
            'category_id' => null,
        ],
    ];
    $preparation = ($this->validCategoryCreationPreparation)();
    $preparation['capability'] = 'receipt.breakdown.mutation.prepare';
    $preparation['input'] = [
        'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf3a0',
        'operation' => 'update_draft',
        'receipt_breakdown_id' => $draft->id,
        'expected_revision' => 1,
        'line_items' => $lineItems,
    ];
    $preparation['interaction']['message_id'] = 'telegram-owner-receipt-update-prepare';
    $unsafePreparation = $preparation;
    $unsafePreparation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf39f';
    $unsafePreparation['input']['line_items'][0]['line_total_minor'] = 9_007_199_254_740_992;
    $unsafePreparation['interaction']['message_id'] = 'telegram-owner-receipt-update-unsafe';

    ($this->callOpenClaw)($unsafePreparation)->assertUnprocessable();

    expect(OpenClawPendingOperation::query()->count())->toBe(0);

    $operation = ($this->callOpenClaw)($preparation)
        ->assertSuccessful()
        ->assertJsonPath(
            'pending_operation.effect_summary',
            "Replace draft Receipt Breakdown #{$draft->id} at revision 1 with 2 purchased items totaling 2500 minor units.",
        )
        ->json('pending_operation');

    $lineItems[0]['description'] = 'Coffee beans';

    $this->actingAs($owner)->put(route('receipt_breakdowns.update', $draft), [
        'expected_revision' => 1,
        'line_items' => $lineItems,
    ])->assertSessionHasNoErrors();

    $confirmation = ($this->validCategoryConfirmation)($operation);
    $confirmation['capability'] = 'receipt.breakdown.mutation.confirm';
    $confirmation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf3a1';
    $confirmation['interaction']['message_id'] = 'telegram-owner-receipt-update-approve-stale';

    ($this->callOpenClaw)($confirmation)->assertConflict();

    expect($draft->refresh()->revision)->toBe(2)
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))->toBe('stale_revision');

    $preparation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf3a2';
    $preparation['input']['expected_revision'] = 2;
    $preparation['interaction']['message_id'] = 'telegram-owner-receipt-update-fresh';
    $operation = ($this->callOpenClaw)($preparation)->assertSuccessful()->json('pending_operation');
    $confirmation = ($this->validCategoryConfirmation)($operation);
    $confirmation['capability'] = 'receipt.breakdown.mutation.confirm';
    $confirmation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf3a3';
    $confirmation['interaction']['message_id'] = 'telegram-owner-receipt-update-approve';

    ($this->callOpenClaw)($confirmation)
        ->assertSuccessful()
        ->assertJsonPath('mutation.operation', 'update_draft')
        ->assertJsonPath('mutation.revision', 3);

    $confirmationPreparation = $preparation;
    $confirmationPreparation['input'] = [
        'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf3a4',
        'operation' => 'confirm_draft',
        'receipt_breakdown_id' => $draft->id,
        'expected_revision' => 3,
    ];
    $confirmationPreparation['interaction']['message_id'] = 'telegram-owner-receipt-confirm-prepare';
    $operation = ($this->callOpenClaw)($confirmationPreparation)
        ->assertSuccessful()
        ->json('pending_operation');
    $confirmation = ($this->validCategoryConfirmation)($operation);
    $confirmation['capability'] = 'receipt.breakdown.mutation.confirm';
    $confirmation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf3a5';
    $confirmation['interaction']['message_id'] = 'telegram-owner-receipt-confirm-approve';

    $category->increment('revision');

    ($this->callOpenClaw)($confirmation)->assertConflict();

    expect(OpenClawConfirmationGrant::query()->count())->toBe(1)
        ->and($draft->refresh()->status)->toBe('draft');

    $confirmationPreparation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf3a6';
    $confirmationPreparation['interaction']['message_id'] = 'telegram-owner-receipt-confirm-fresh';
    $operation = ($this->callOpenClaw)($confirmationPreparation)
        ->assertSuccessful()
        ->json('pending_operation');
    $confirmation = ($this->validCategoryConfirmation)($operation);
    $confirmation['capability'] = 'receipt.breakdown.mutation.confirm';
    $confirmation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf3a7';
    $confirmation['interaction']['message_id'] = 'telegram-owner-receipt-confirm-approve-fresh';

    ($this->callOpenClaw)($confirmation)
        ->assertSuccessful()
        ->assertJsonPath('mutation.operation', 'confirm_draft')
        ->assertJsonPath('mutation.status', 'confirmed')
        ->assertJsonPath('mutation.revision', 3);

    $readPayload = ($this->validPayload)($transaction->id);
    $readPayload['interaction']['message_id'] = 'telegram-owner-receipt-read-confirmed';

    ($this->callOpenClaw)($readPayload)
        ->assertSuccessful()
        ->assertJsonPath('transaction.receipt_breakdown.draft', null)
        ->assertJsonPath('transaction.receipt_breakdown.confirmed.revision', 3)
        ->assertJsonPath('transaction.receipt_breakdown.confirmed.total_minor', '2500');
});

test('OpenClaw Category assignment confirmation expires when the target Category changes', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $operation = ($this->callOpenClaw)(
        ($this->validCategoryAssignmentPreparation)($transaction, $category),
    )->assertSuccessful()->json('pending_operation');

    $this->actingAs($owner)->patch(route('categories.update', $category), [
        'expected_revision' => 1,
        'name' => 'Markets',
        'parent_id' => null,
        'description' => null,
        'examples' => [],
    ])->assertSessionHasNoErrors();

    ($this->callOpenClaw)(($this->validCategoryConfirmation)($operation))
        ->assertConflict();

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))->toBe('stale_revision');
});

test('OpenClaw Category reactivation confirmation expires when its parent changes', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $child = Category::factory()->for($owner, 'owner')->for($parent, 'parent')->create([
        'name' => 'Dining',
        'retired_at' => now(),
        'revision' => 2,
    ]);
    $preparation = ($this->validCategoryCreationPreparation)();
    $preparation['input'] = [
        'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf385',
        'operation' => 'reactivate',
        'category_id' => $child->id,
        'expected_revision' => 2,
    ];
    $preparation['interaction']['message_id'] = 'telegram-owner-message-reactivate-prepare';
    $operation = ($this->callOpenClaw)($preparation)->assertSuccessful()->json('pending_operation');

    $this->actingAs($owner)->patch(route('categories.update', $parent), [
        'expected_revision' => 1,
        'name' => 'Meals',
        'parent_id' => null,
        'description' => null,
        'examples' => [],
    ])->assertSessionHasNoErrors();

    $confirmation = ($this->validCategoryConfirmation)($operation);
    $confirmation['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf386';

    ($this->callOpenClaw)($confirmation)->assertConflict();

    expect($child->fresh()->retired_at)->not->toBeNull()
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))->toBe('stale_revision');
});

test('OpenClaw prepares a completely validated manual Transaction with its exact effect', function () {
    $this->freezeTime();
    User::factory()->create();
    $expiresAt = now()->addMinutes(30);

    ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->assertJsonPath('schema_version', 1)
        ->assertJsonPath('pending_operation.revision', 1)
        ->assertJsonPath('pending_operation.expires_at', $expiresAt->toIso8601String())
        ->assertJsonPath(
            'pending_operation.effect_summary',
            'Record a purchase of USD 123.45 on 2026-07-24 at Neighborhood market.',
        )
        ->assertJson(fn ($json) => $json
            ->whereType('pending_operation.id', 'string')
            ->whereType('pending_operation.payload_digest', 'string')
            ->etc());

    expect(Transaction::query()->count())->toBe(0);
});

test('manual Transaction preparation rejects non-canonical input types', function () {
    User::factory()->create();
    $payload = ($this->validManualTransactionPreparation)();
    $payload['input']['amount_minor'] = '12345';

    ($this->callOpenClaw)($payload)->assertUnprocessable();

    expect(OpenClawPendingOperation::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->sole()->outcome)->toBe('invalid_request');
});

test('manual Transaction preparation validates every operation field', function (Closure $changeInput) {
    User::factory()->create();
    $payload = ($this->validManualTransactionPreparation)();
    $changeInput($payload['input']);

    ($this->callOpenClaw)($payload)->assertUnprocessable();

    expect(OpenClawPendingOperation::query()->count())->toBe(0);
})->with([
    'invalid date' => [function (array &$input): void {
        $input['occurred_on'] = '2026-02-30';
    }],
    'zero amount' => [function (array &$input): void {
        $input['amount_minor'] = 0;
    }],
    'fractional amount' => [function (array &$input): void {
        $input['amount_minor'] = 12.5;
    }],
    'unsupported currency' => [function (array &$input): void {
        $input['currency'] = 'EUR';
    }],
    'unsupported kind' => [function (array &$input): void {
        $input['kind'] = 'transfer';
    }],
    'blank description' => [function (array &$input): void {
        $input['merchant_description'] = '   ';
    }],
    'long description' => [function (array &$input): void {
        $input['merchant_description'] = str_repeat('a', 256);
    }],
    'expanded shape' => [function (array &$input): void {
        $input['confirmed_at'] = now()->toIso8601String();
    }],
]);

test('manual Transaction preparation is idempotent only for identical input', function () {
    User::factory()->create();
    $payload = ($this->validManualTransactionPreparation)();

    $firstResponse = ($this->callOpenClaw)($payload)->assertSuccessful();
    $retryResponse = ($this->callOpenClaw)($payload)->assertSuccessful();
    $changedPayload = $payload;
    $changedPayload['input']['amount_minor'] = 12346;
    ($this->callOpenClaw)($changedPayload)->assertConflict();

    expect($retryResponse->json('pending_operation'))->toBe($firstResponse->json('pending_operation'))
        ->and(OpenClawPendingOperation::query()->count())->toBe(1)
        ->and(OpenClawAuditEvent::query()->pluck('outcome')->all())->toBe([
            'success',
            'success',
            'idempotency_conflict',
        ]);
});

test('preparing another operation cancels and revises the prior pending operation', function () {
    User::factory()->create();

    $firstResponse = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful();
    $secondPayload = ($this->validManualTransactionPreparation)();
    $secondPayload['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf373';
    $secondPayload['input']['amount_minor'] = 9000;
    $secondPayload['interaction']['message_id'] = 'telegram-owner-message-replacement';
    $secondResponse = ($this->callOpenClaw)($secondPayload)->assertSuccessful();

    $firstOperation = OpenClawPendingOperation::query()
        ->where('operation_id', $firstResponse->json('pending_operation.id'))
        ->sole();
    $secondOperation = OpenClawPendingOperation::query()
        ->where('operation_id', $secondResponse->json('pending_operation.id'))
        ->sole();

    expect($firstOperation->canceled_at)->not->toBeNull()
        ->and($firstOperation->revision)->toBe(2)
        ->and($secondOperation->canceled_at)->toBeNull()
        ->and($secondOperation->revision)->toBe(1)
        ->and(OpenClawPendingOperation::query()
            ->whereNull('canceled_at')
            ->whereNull('confirmed_at')
            ->count())->toBe(1);
});

test('retrying a superseded preparation returns its original outcome', function () {
    User::factory()->create();
    $firstPayload = ($this->validManualTransactionPreparation)();
    $firstResponse = ($this->callOpenClaw)($firstPayload)->assertSuccessful();
    $replacement = ($this->validManualTransactionPreparation)();
    $replacement['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf373';
    $replacement['interaction']['message_id'] = 'telegram-owner-message-replacement';
    ($this->callOpenClaw)($replacement)->assertSuccessful();

    $retryResponse = ($this->callOpenClaw)($firstPayload)->assertSuccessful();

    expect($retryResponse->json('pending_operation'))
        ->toBe($firstResponse->json('pending_operation'));
});

test('a new admitted owner message confirms the exact prepared manual Transaction', function () {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');

    $response = ($this->callOpenClaw)(($this->validManualTransactionConfirmation)($operation))
        ->assertSuccessful()
        ->assertJsonPath('schema_version', 1)
        ->assertJsonPath('transaction.revision', 1)
        ->assertJsonPath('transaction.occurred_on', '2026-07-24')
        ->assertJsonPath('transaction.amount_minor', '12345')
        ->assertJsonPath('transaction.currency', 'USD')
        ->assertJsonPath('transaction.kind', 'purchase')
        ->assertJsonPath('transaction.merchant_description', 'Neighborhood market')
        ->assertJsonPath('transaction.status', 'active');

    $transaction = Transaction::query()->sole();
    $pendingOperation = OpenClawPendingOperation::query()->sole();

    expect($response->json('transaction.id'))->toBe($transaction->id)
        ->and($transaction->confirmed_at)->not->toBeNull()
        ->and($pendingOperation->confirmed_at)->not->toBeNull();
});

test('an identical successful mutation retry returns its original outcome once', function () {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');
    $confirmation = ($this->validManualTransactionConfirmation)($operation);

    $firstResponse = ($this->callOpenClaw)($confirmation)->assertSuccessful();
    $currentTransaction = Transaction::query()->sole();
    $currentTransaction->voided_at = now();
    $currentTransaction->revision = 2;
    $currentTransaction->save();
    $retryResponse = ($this->callOpenClaw)($confirmation)->assertSuccessful();

    expect($retryResponse->json('transaction'))->toBe($firstResponse->json('transaction'))
        ->and(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->sole()->revision)->toBe(2)
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(1)
        ->and(OpenClawAuditEvent::query()->where('event_kind', 'mutation')->count())->toBe(1)
        ->and(OpenClawAuditEvent::query()->pluck('outcome')->all())->toBe([
            'success',
            'success',
            'idempotent_replay',
        ]);
});

test('confirmation requires a new admitted owner message', function () {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');
    $confirmation = ($this->validManualTransactionConfirmation)($operation);
    $confirmation['interaction']['message_id'] = 'telegram-owner-message-prepare';

    ($this->callOpenClaw)($confirmation)->assertConflict();

    expect(Transaction::query()->count())->toBe(0)
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))
        ->toBe('approval_message_required');
});

test('a different owner message from before preparation cannot approve it', function () {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');
    $confirmation = ($this->validManualTransactionConfirmation)($operation);
    $confirmation['interaction']['message_id'] = 'telegram-owner-message-older';
    $confirmation['interaction']['occurred_at'] = now()->subSecond()->toIso8601String();

    ($this->callOpenClaw)($confirmation)->assertConflict();

    expect(Transaction::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))
        ->toBe('approval_message_required');
});

test('a prepared operation expires after thirty minutes', function () {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');

    $this->travel(30)->minutes();

    ($this->callOpenClaw)(($this->validManualTransactionConfirmation)($operation))
        ->assertConflict();

    expect(Transaction::query()->count())->toBe(0)
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))
        ->toBe('confirmation_expired');
});

test('superseded pending-operation revisions fail closed', function () {
    User::factory()->create();
    $firstOperation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');
    $replacement = ($this->validManualTransactionPreparation)();
    $replacement['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf375';
    $replacement['interaction']['message_id'] = 'telegram-owner-message-replacement';
    ($this->callOpenClaw)($replacement)->assertSuccessful();

    ($this->callOpenClaw)(($this->validManualTransactionConfirmation)($firstOperation))
        ->assertConflict();

    expect(Transaction::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->latest('id')->value('outcome'))
        ->toBe('stale_revision');
});

test('payload and schema changes invalidate confirmation', function (string $change) {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');
    $confirmation = ($this->validManualTransactionConfirmation)($operation);

    if ($change === 'payload') {
        $confirmation['input']['payload_digest'] = str_repeat('0', 64);
    } else {
        $confirmation['schema_version'] = 2;
    }

    $response = ($this->callOpenClaw)($confirmation);

    if ($change === 'payload') {
        $response->assertConflict();
    } else {
        $response->assertUnprocessable();
    }

    expect(Transaction::query()->count())->toBe(0)
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0);
})->with(['payload', 'schema']);

test('changed mutation idempotency input conflicts and a new key cannot reuse the grant', function () {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');
    $confirmation = ($this->validManualTransactionConfirmation)($operation);
    ($this->callOpenClaw)($confirmation)->assertSuccessful();

    $changedInput = $confirmation;
    $changedInput['input']['payload_digest'] = str_repeat('0', 64);
    ($this->callOpenClaw)($changedInput)->assertConflict();

    $newCommand = $confirmation;
    $newCommand['input']['idempotency_key'] = '01983d79-a780-72f0-bb34-9b4f3f0cf376';
    ($this->callOpenClaw)($newCommand)->assertConflict();

    expect(Transaction::query()->count())->toBe(1)
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(1)
        ->and(OpenClawAuditEvent::query()->pluck('outcome')->all())->toBe([
            'success',
            'success',
            'idempotency_conflict',
            'confirmation_consumed',
        ]);
});

test('a failed mutation audit rolls back the Transaction and Confirmation Grant', function () {
    User::factory()->create();
    $operation = ($this->callOpenClaw)(($this->validManualTransactionPreparation)())
        ->assertSuccessful()
        ->json('pending_operation');
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION reject_open_claw_mutation_audit_event_insert() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'Mutation audit unavailable.' USING ERRCODE = '23514';
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER open_claw_mutation_audit_events_reject_insert
        BEFORE INSERT ON open_claw_audit_events
        FOR EACH ROW EXECUTE FUNCTION reject_open_claw_mutation_audit_event_insert();
        SQL);

    try {
        ($this->callOpenClaw)(($this->validManualTransactionConfirmation)($operation))
            ->assertServerError();
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS open_claw_mutation_audit_events_reject_insert ON open_claw_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_open_claw_mutation_audit_event_insert()');
    }

    expect(Transaction::query()->count())->toBe(0)
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0)
        ->and(OpenClawPendingOperation::query()->sole()->confirmed_at)->toBeNull();
});

test('OpenClaw can read one field-minimized owner Transaction', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-24',
        'amount_minor' => 12345,
        'currency' => Currency::Usd,
        'kind' => TransactionKind::Purchase,
        'merchant_description' => 'Neighborhood market',
        'revision' => 2,
        'voided_at' => null,
    ]);

    ($this->callOpenClaw)(($this->validPayload)($transaction->id))
        ->assertSuccessful()
        ->assertExactJson([
            'schema_version' => 1,
            'transaction' => [
                'id' => $transaction->id,
                'revision' => 2,
                'occurred_on' => '2026-07-24',
                'amount_minor' => '12345',
                'currency' => 'USD',
                'kind' => 'purchase',
                'merchant_description' => 'Neighborhood market',
                'status' => 'active',
                'category' => null,
                'receipt_breakdown' => [
                    'draft' => null,
                    'confirmed' => null,
                ],
            ],
        ]);

    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect($auditEvent->outcome)->toBe('success')
        ->and($auditEvent->http_status)->toBe(200)
        ->and($auditEvent->result_count)->toBe(1)
        ->and($auditEvent->capability)->toBe('transaction.read')
        ->and(json_encode($auditEvent->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('Neighborhood market')
        ->not->toContain('12345');
});

test('the capability transport fails closed for missing credentials and replayed requests', function () {
    $this->postJson('/api/openclaw/v1/transport')->assertUnauthorized();

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);
    $nonce = '01983d79-a780-72f0-bb34-9b4f3f0cf372';

    ($this->callOpenClaw)($payload, nonce: $nonce)->assertSuccessful();
    ($this->callOpenClaw)($payload, nonce: $nonce)->assertConflict();

    $this->travel(300)->seconds();

    ($this->callOpenClaw)(
        $payload,
        nonce: $nonce,
        timestamp: (string) now()->subSeconds(300)->getTimestamp(),
    )->assertConflict();

    expect(OpenClawAuditEvent::query()->pluck('outcome')->all())
        ->toBe(['success', 'replayed_nonce', 'replayed_nonce'])
        ->and(DB::table('open_claw_request_nonces')->count())->toBe(1);
});

test('unauthenticated ingress is rate limited before signature verification', function () {
    RateLimiter::for('openclaw-ingress', fn (): Limit => Limit::perMinute(1));

    $this->postJson('/api/openclaw/v1/transport')->assertUnauthorized();
    $this->postJson('/api/openclaw/v1/transport')->assertTooManyRequests();
    expect(OpenClawAuditEvent::query()->count())->toBe(0);
});

test('authenticated rate-limit failures are audited', function () {
    config(['services.openclaw.capability.rate_limit_per_minute' => 1]);

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);

    ($this->callOpenClaw)($payload)->assertSuccessful();
    ($this->callOpenClaw)($payload)->assertTooManyRequests();

    expect(OpenClawAuditEvent::query()->pluck('outcome')->all())
        ->toBe(['success', 'rate_limited']);
});

test('signature verification binds timestamp nonce method path and exact body', function (array $signatureOverrides) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    ($this->callOpenClaw)(
        ($this->validPayload)($transaction->id),
        signatureOverrides: $signatureOverrides,
    )->assertUnauthorized();

    expect(OpenClawAuditEvent::query()->count())->toBe(0)
        ->and(DB::table('open_claw_request_nonces')->count())->toBe(0);
})->with([
    'timestamp' => [['timestamp' => '1']],
    'nonce' => [['nonce' => 'different-signed-nonce']],
    'method' => [['method' => 'GET']],
    'path' => [['path' => '/api/openclaw/v1/different']],
    'body digest' => [['body' => '{"different":true}']],
]);

test('validly signed stale and malformed authentication claims are rejected and audited', function (
    ?string $nonce,
    int $timestampOffset,
    string $expectedOutcome,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $timestamp = (string) now()->addSeconds($timestampOffset)->getTimestamp();

    ($this->callOpenClaw)(
        ($this->validPayload)($transaction->id),
        nonce: $nonce,
        timestamp: $timestamp,
    )->assertUnauthorized();

    expect(OpenClawAuditEvent::query()->sole()->outcome)->toBe($expectedOutcome);
})->with([
    'stale timestamp' => [
        null,
        -301,
        'stale_signature',
    ],
    'future timestamp' => [
        null,
        301,
        'stale_signature',
    ],
    'malformed nonce' => [
        'short',
        0,
        'invalid_request',
    ],
]);

test('unsupported schemas capabilities and expanded request shapes fail closed', function (
    Closure $changePayload,
    string $expectedOutcome,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);
    $changePayload($payload);

    ($this->callOpenClaw)($payload)->assertUnprocessable();

    expect(OpenClawAuditEvent::query()->sole()->outcome)->toBe($expectedOutcome);
})->with([
    'missing schema version' => [
        function (array &$payload): void {
            unset($payload['schema_version']);
        },
        'unsupported_schema',
    ],
    'unsupported schema version' => [
        function (array &$payload): void {
            $payload['schema_version'] = 2;
        },
        'unsupported_schema',
    ],
    'different capability' => [
        function (array &$payload): void {
            $payload['capability'] = 'transaction.list';
        },
        'unsupported_capability',
    ],
    'caller-selected owner' => [
        function (array &$payload): void {
            $payload['input']['owner_id'] = 1;
        },
        'unbound_interaction',
    ],
    'caller-selected fields' => [
        function (array &$payload): void {
            $payload['input']['fields'] = ['*'];
        },
        'unbound_interaction',
    ],
    'unknown top-level field' => [
        function (array &$payload): void {
            $payload['debug'] = true;
        },
        'invalid_request',
    ],
]);

test('malformed signed JSON fails closed and still creates a value-free audit event', function () {
    $body = '{"schema_version":';
    $timestamp = (string) now()->getTimestamp();
    $nonce = (string) Str::uuid();
    $signature = sodium_crypto_sign_detached(implode("\n", [
        $timestamp,
        $nonce,
        'POST',
        '/api/openclaw/v1/transport',
        hash('sha256', $body),
    ]), $this->openClawPrivateKey);

    $this->call(
        'POST',
        '/api/openclaw/v1/transport',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MONEY_ASSISTANT_KEY_ID' => 'openclaw-service-2026-07',
            'HTTP_X_MONEY_ASSISTANT_TIMESTAMP' => $timestamp,
            'HTTP_X_MONEY_ASSISTANT_NONCE' => $nonce,
            'HTTP_X_MONEY_ASSISTANT_SIGNATURE' => base64_encode($signature),
        ],
        content: $body,
    )->assertUnprocessable();

    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect($auditEvent->outcome)->toBe('invalid_request')
        ->and($auditEvent->capability)->toBeNull()
        ->and($auditEvent->schema_version)->toBeNull()
        ->and($auditEvent->result_count)->toBe(0);
});

test('the capability requires a current message from the admitted owner interaction', function (
    Closure $changeInteraction,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $payload = ($this->validPayload)($transaction->id);
    $changeInteraction($payload['interaction']);

    ($this->callOpenClaw)($payload)->assertUnprocessable();

    expect(OpenClawAuditEvent::query()->sole()->outcome)->toBe('unbound_interaction');
})->with([
    'wrong interaction kind' => [function (array &$interaction): void {
        $interaction['kind'] = 'scheduled_task';
    }],
    'wrong agent' => [function (array &$interaction): void {
        $interaction['agent_id'] = 'default';
    }],
    'wrong account' => [function (array &$interaction): void {
        $interaction['account_id'] = 'default';
    }],
    'wrong conversation' => [function (array &$interaction): void {
        $interaction['conversation_id'] = 'another-chat';
    }],
    'wrong owner sender' => [function (array &$interaction): void {
        $interaction['owner_sender_id'] = 'another-user';
    }],
    'message older than thirty minutes' => [function (array &$interaction): void {
        $interaction['occurred_at'] = now()->subSeconds(1801)->toIso8601String();
    }],
    'future message' => [function (array &$interaction): void {
        $interaction['occurred_at'] = now()->addMinute()->toIso8601String();
    }],
]);

test('missing owner data and unknown Transactions return a minimized not found response', function (
    bool $createOwner,
) {
    $owner = $createOwner ? User::factory()->create() : null;
    $transactionId = $owner === null
        ? 1
        : Transaction::factory()->for($owner, 'owner')->create()->id + 1;

    ($this->callOpenClaw)(($this->validPayload)($transactionId))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Transaction not found.']);

    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect($auditEvent->outcome)->toBe('not_found')
        ->and($auditEvent->result_count)->toBe(0);
})->with([
    'no owner' => [false],
    'unknown Transaction' => [true],
]);

test('authenticated calls fail closed when their audit cannot be appended', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION reject_open_claw_audit_event_insert() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'Audit unavailable.' USING ERRCODE = '23514';
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER open_claw_audit_events_reject_insert
        BEFORE INSERT ON open_claw_audit_events
        FOR EACH ROW EXECUTE FUNCTION reject_open_claw_audit_event_insert();
        SQL);

    try {
        ($this->callOpenClaw)(($this->validPayload)($transaction->id))
            ->assertServerError()
            ->assertJsonMissing(['amount_minor' => (string) $transaction->amount_minor]);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS open_claw_audit_events_reject_insert ON open_claw_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_open_claw_audit_event_insert()');
    }
});

test('OpenClaw audit events are database-enforced append-only records', function (string $operation) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    ($this->callOpenClaw)(($this->validPayload)($transaction->id))->assertSuccessful();
    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect(fn () => DB::transaction(function () use ($operation, $auditEvent): void {
        if ($operation === 'update') {
            DB::table('open_claw_audit_events')
                ->where('id', $auditEvent->id)
                ->update(['outcome' => 'invalid_request']);

            return;
        }

        DB::table('open_claw_audit_events')->where('id', $auditEvent->id)->delete();
    }))->toThrow(QueryException::class);
})->with(['update', 'delete']);

test('OpenClaw audit constraints reject unsupported or value-retaining outcomes', function (
    array $invalidAttributes,
) {
    expect(fn () => DB::transaction(fn () => DB::table('open_claw_audit_events')->insert([
        'occurred_at' => now(),
        'service_key_id' => 'openclaw-service-2026-07',
        'schema_version' => 1,
        'capability' => 'transaction.read',
        'outcome' => 'success',
        'http_status' => 200,
        'nonce_digest' => str_repeat('a', 64),
        'request_digest' => str_repeat('b', 64),
        'interaction_digest' => str_repeat('c', 64),
        'resource_type' => 'transaction',
        'result_count' => 1,
        ...$invalidAttributes,
    ])))->toThrow(QueryException::class);
})->with([
    'unsupported outcome' => [['outcome' => 'returned_amount_12345']],
    'unbounded result count' => [['result_count' => 2]],
    'invalid status' => [['http_status' => 700]],
]);

test('Laravel sends only a minimal event through the fixed mapped hook', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:18789/hooks/money-assistant' => Http::response(status: 202),
    ]);

    app(OpenClawHook::class)->dispatch(
        eventId: '01J3AGV2C8ZQJ9W7K1M4B5N6P7',
        eventType: 'transport.probe',
        occurredAt: CarbonImmutable::parse('2026-07-24T15:00:00Z'),
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:18789/hooks/money-assistant'
        && $request->hasHeader('Authorization', 'Bearer outbound-hook-token')
        && $request->data() === [
            'event_id' => '01J3AGV2C8ZQJ9W7K1M4B5N6P7',
            'event_type' => 'transport.probe',
            'occurred_at' => '2026-07-24T15:00:00Z',
        ]
    );
});

test('the mapped hook retries transient failures', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:18789/hooks/money-assistant' => Http::sequence()
            ->pushStatus(503)
            ->pushStatus(202),
    ]);

    app(OpenClawHook::class)->dispatch(
        eventId: '01J3AGV2C8ZQJ9W7K1M4B5N6P7',
        eventType: 'transport.probe',
        occurredAt: CarbonImmutable::parse('2026-07-24T15:00:00Z'),
    );

    Http::assertSentCount(2);
});

test('the mapped hook does not retry deterministic failures', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:18789/hooks/money-assistant' => Http::response(status: 422),
    ]);

    expect(fn () => app(OpenClawHook::class)->dispatch(
        eventId: '01J3AGV2C8ZQJ9W7K1M4B5N6P8',
        eventType: 'transport.probe',
        occurredAt: CarbonImmutable::parse('2026-07-24T15:00:00Z'),
    ))->toThrow(RequestException::class);

    Http::assertSentCount(1);
});
