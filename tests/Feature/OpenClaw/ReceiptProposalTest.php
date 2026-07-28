<?php

use App\Models\OpenClawAuditEvent;
use App\Models\OpenClawConfirmationGrant;
use App\Models\ReceiptProposal;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
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
    ]);
    RateLimiter::for('openclaw-ingress', fn (): Limit => Limit::perMinute(120));

    $this->callOpenClaw = function (array $payload): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $signature = sodium_crypto_sign_detached(implode("\n", [
            $timestamp,
            $nonce,
            'POST',
            '/api/openclaw/v1/transport',
            hash('sha256', $body),
        ]), $this->openClawPrivateKey);

        return $this->call(
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
        );
    };

    $this->validReceiptProposal = fn (): array => [
        'schema_version' => 1,
        'capability' => 'receipt.proposal.submit',
        'interaction' => [
            'kind' => 'owner_photo_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'telegram-owner-photo-456',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => [
            'proposal_id' => '01983d79-a780-72f0-bb34-9b4f3f0cf390',
            'source_kind' => 'receipt_photo',
            'processed_at' => now()->toIso8601String(),
            'provider' => 'openai',
            'model' => 'openai/gpt-5.6',
            'contract_version' => 1,
            'transaction' => [
                'occurred_on' => '2026-07-28',
                'amount_minor' => 2590,
                'currency' => 'PEN',
                'kind' => 'purchase',
                'merchant_description' => 'Neighborhood market',
            ],
            'line_items' => [
                [
                    'description' => 'Coffee beans',
                    'line_total_minor' => 2590,
                ],
            ],
        ],
    ];
});

test('an image-free Receipt Proposal from a distinct owner photo is accepted without confirmation', function () {
    $owner = User::factory()->create();
    $payload = ($this->validReceiptProposal)();

    ($this->callOpenClaw)($payload)
        ->assertSuccessful()
        ->assertExactJson([
            'schema_version' => 1,
            'receipt_proposal' => [
                'id' => $payload['input']['proposal_id'],
                'status' => 'accepted',
            ],
        ]);

    $proposal = ReceiptProposal::query()->sole();
    $auditEvent = OpenClawAuditEvent::query()->sole();

    expect($proposal->user_id)->toBe($owner->id)
        ->and($proposal->proposal_id)->toBe($payload['input']['proposal_id'])
        ->and($proposal->source_kind)->toBe('receipt_photo')
        ->and($proposal->processed_at->toIso8601String())->toBe($payload['input']['processed_at'])
        ->and($proposal->provider)->toBe('openai')
        ->and($proposal->model)->toBe('openai/gpt-5.6')
        ->and($proposal->contract_version)->toBe(1)
        ->and($proposal->proposed_transaction)->toEqual($payload['input']['transaction'])
        ->and($proposal->proposed_line_items)->toEqual($payload['input']['line_items'])
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0)
        ->and($auditEvent->event_kind)->toBe('proposal')
        ->and($auditEvent->service_key_id)->toBe('openclaw-service-2026-07')
        ->and($auditEvent->schema_version)->toBe(1)
        ->and($auditEvent->idempotency_key)->toBe($payload['input']['proposal_id'])
        ->and($auditEvent->operation_digest)->toMatch('/^[a-f0-9]{64}$/')
        ->and($auditEvent->confirmation_grant_id)->toBeNull()
        ->and($auditEvent->domain_action)->toBe('receipt_proposal.submit');
});

test('rolling back the proposal audit contract removes unsupported append-only events', function () {
    User::factory()->create();

    ($this->callOpenClaw)(($this->validReceiptProposal)())->assertSuccessful();

    $migration = require database_path('migrations/2026_07_28_060036_add_receipt_proposal_contract_to_open_claw_audit_events_table.php');
    $migration->down();

    $constraint = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conname = 'open_claw_audit_events_mutation_metadata_consistent'
        SQL);
    $trigger = DB::selectOne(<<<'SQL'
        SELECT tgname
        FROM pg_trigger
        WHERE tgname = 'open_claw_audit_events_append_only'
          AND NOT tgisinternal
        SQL);

    expect(OpenClawAuditEvent::query()->count())->toBe(0)
        ->and($constraint?->definition)->not->toContain("event_kind = 'proposal'")
        ->and($trigger?->tgname)->toBe('open_claw_audit_events_append_only');
});

test('an identical Receipt Proposal retry returns its original result', function () {
    User::factory()->create();
    $payload = ($this->validReceiptProposal)();

    $firstResponse = ($this->callOpenClaw)($payload)->assertSuccessful();
    $payload['interaction']['message_id'] = 'telegram-owner-photo-retry';
    $payload['interaction']['occurred_at'] = now()->toIso8601String();
    $secondResponse = ($this->callOpenClaw)($payload)->assertSuccessful();

    expect($secondResponse->json())->toBe($firstResponse->json())
        ->and(ReceiptProposal::query()->count())->toBe(1)
        ->and(OpenClawAuditEvent::query()->pluck('outcome')->all())->toBe([
            'success',
            'idempotent_replay',
        ])
        ->and(OpenClawAuditEvent::query()->pluck('event_kind')->all())->toBe([
            'proposal',
            'request',
        ]);
});

test('changed reuse of a Receipt Proposal identifier is rejected', function () {
    User::factory()->create();
    $payload = ($this->validReceiptProposal)();

    ($this->callOpenClaw)($payload)->assertSuccessful();
    $payload['interaction']['message_id'] = 'telegram-owner-photo-changed';
    $payload['input']['line_items'][0]['description'] = 'Different item';

    ($this->callOpenClaw)($payload)
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Proposal identifier conflicts with an earlier proposal.',
        ]);

    expect(ReceiptProposal::query()->count())->toBe(1)
        ->and(OpenClawAuditEvent::query()->pluck('outcome')->all())->toBe([
            'success',
            'idempotency_conflict',
        ]);
});

test('Receipt Proposals reject unapproved provenance and sensitive content', function (
    Closure $changePayload,
) {
    User::factory()->create();
    $payload = ($this->validReceiptProposal)();
    $changePayload($payload);

    ($this->callOpenClaw)($payload)->assertUnprocessable();

    expect(ReceiptProposal::query()->count())->toBe(0)
        ->and(OpenClawAuditEvent::query()->sole()->outcome)->toBeIn([
            'invalid_request',
            'unbound_interaction',
        ]);
})->with([
    'image bytes' => [function (array &$payload): void {
        $payload['input']['image'] = base64_encode('private receipt image');
    }],
    'image hash' => [function (array &$payload): void {
        $payload['input']['image_hash'] = str_repeat('a', 64);
    }],
    'image path' => [function (array &$payload): void {
        $payload['input']['image_path'] = '/tmp/private-receipt.jpg';
    }],
    'Telegram identifier' => [function (array &$payload): void {
        $payload['input']['telegram_message_id'] = 'telegram-owner-photo-456';
    }],
    'raw OCR' => [function (array &$payload): void {
        $payload['input']['transaction']['raw_ocr'] = 'full receipt text';
    }],
    'prompt' => [function (array &$payload): void {
        $payload['input']['prompt'] = 'extract every field';
    }],
    'reasoning' => [function (array &$payload): void {
        $payload['input']['reasoning'] = 'hidden chain of thought';
    }],
    'token log' => [function (array &$payload): void {
        $payload['input']['token_log'] = ['input' => 1000];
    }],
    'provider credentials' => [function (array &$payload): void {
        $payload['input']['api_key'] = 'secret';
    }],
    'wrong source kind' => [function (array &$payload): void {
        $payload['input']['source_kind'] = 'telegram_photo';
    }],
    'wrong provider' => [function (array &$payload): void {
        $payload['input']['provider'] = 'other';
    }],
    'wrong model' => [function (array &$payload): void {
        $payload['input']['model'] = 'openai/gpt-5.6-mini';
    }],
    'wrong contract version' => [function (array &$payload): void {
        $payload['input']['contract_version'] = 2;
    }],
    'non-photo owner message' => [function (array &$payload): void {
        $payload['interaction']['kind'] = 'owner_message';
    }],
]);

test('Receipt Proposal acceptance rolls back when its protected audit cannot be appended', function () {
    User::factory()->create();
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION reject_receipt_proposal_audit_insert() RETURNS trigger AS $$
        BEGIN
            IF NEW.capability = 'receipt.proposal.submit' THEN
                RAISE EXCEPTION 'Audit unavailable.' USING ERRCODE = '23514';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER open_claw_audit_events_reject_receipt_proposal
        BEFORE INSERT ON open_claw_audit_events
        FOR EACH ROW EXECUTE FUNCTION reject_receipt_proposal_audit_insert();
        SQL);

    try {
        ($this->callOpenClaw)(($this->validReceiptProposal)())->assertServerError();

        expect(ReceiptProposal::query()->count())->toBe(0);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS open_claw_audit_events_reject_receipt_proposal ON open_claw_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_receipt_proposal_audit_insert()');
    }
});
