<?php

use App\Integrations\OpenClaw\HttpOpenClawHook;
use App\Models\GmailConnection;
use App\Models\OpenClawAuditEvent;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

test('retained Gmail credentials are encrypted hidden and rewrapped after an application key rotation', function () {
    $connection = GmailConnection::factory()->create([
        'access_token' => 'sensitive-access-token',
        'refresh_token' => 'sensitive-refresh-token',
    ]);
    $storedBeforeRotation = DB::table('gmail_connections')->where('id', $connection->id)->sole();

    expect($storedBeforeRotation->access_token)->not->toContain('sensitive-access-token')
        ->and($storedBeforeRotation->refresh_token)->not->toContain('sensitive-refresh-token')
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);

    $oldKey = config('app.key');
    $newKey = 'base64:'.base64_encode(random_bytes(32));

    config([
        'app.key' => $newKey,
        'app.previous_keys' => [$oldKey],
    ]);
    Crypt::clearResolvedInstance('encrypter');
    app()->forgetInstance('encrypter');
    GmailConnection::encryptUsing(null);

    expect(Artisan::call('app:credentials:rewrap'))->toBe(0)
        ->and(Artisan::output())
        ->not->toContain('sensitive-access-token', 'sensitive-refresh-token');

    $connection->refresh();
    $storedAfterRotation = DB::table('gmail_connections')->where('id', $connection->id)->sole();

    expect($connection->access_token)->toBe('sensitive-access-token')
        ->and($connection->refresh_token)->toBe('sensitive-refresh-token')
        ->and($storedAfterRotation->access_token)->not->toBe($storedBeforeRotation->access_token)
        ->and($storedAfterRotation->refresh_token)->not->toBe($storedBeforeRotation->refresh_token);

    $newEncrypter = new Encrypter(base64_decode(substr($newKey, 7), true), 'AES-256-CBC');

    expect($newEncrypter->decrypt($storedAfterRotation->access_token, false))->toBe('sensitive-access-token')
        ->and($newEncrypter->decrypt($storedAfterRotation->refresh_token, false))->toBe('sensitive-refresh-token');
});

test('the durable schema cannot retain raw Spending Notification or receipt image content', function () {
    $forbiddenColumnNames = [
        'body',
        'image',
        'image_bytes',
        'image_hash',
        'image_path',
        'prompt',
        'raw_content',
        'raw_mime',
        'raw_ocr',
        'subject',
        'reasoning',
        'telegram_message_id',
        'token_log',
    ];

    foreach (['spending_notification_references', 'gmail_message_discoveries', 'receipt_proposals'] as $table) {
        expect(Schema::getColumnListing($table))
            ->not->toContain(...$forbiddenColumnNames);
    }
});

test('production and OpenClaw credentials resolve only through host-managed secret boundaries', function () {
    $compose = file_get_contents(base_path('compose.production.yaml'));
    $environment = file_get_contents(base_path('.env.production.example'));
    $entrypoint = file_get_contents(base_path('docker-entrypoint.production'));
    $openClawService = file_get_contents(base_path('openclaw/money-assistant-openclaw.service.conf'));
    $openClawInstaller = file_get_contents(base_path('openclaw/install-money-assistant-security'));
    $policy = json_decode(
        file_get_contents(base_path('openclaw/money-assistant-agent-policy.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $manifest = json_decode(
        file_get_contents(base_path('openclaw/money-assistant-plugin/openclaw.plugin.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($compose)
        ->toContain('APP_PREVIOUS_KEYS_FILE: /run/secrets/application_previous_keys')
        ->toContain('google_gmail_client_secret')
        ->toContain('openclaw_hook_token')
        ->and($environment)
        ->toContain('APP_PREVIOUS_KEYS_FILE=/etc/money-assistant/secrets/application_previous_keys')
        ->and($entrypoint)
        ->toContain('read_optional_secret APP_PREVIOUS_KEYS')
        ->and($policy['secrets']['providers']['money_assistant_private_key'])->toBe([
            'source' => 'file',
            'path' => '/etc/money-assistant/secrets/openclaw_capability_private_key',
            'mode' => 'singleValue',
        ])
        ->and($policy['plugins']['entries']['money-assistant']['config']['privateKey'])->toBe([
            'source' => 'file',
            'provider' => 'money_assistant_private_key',
            'id' => 'value',
        ])
        ->and($policy['hooks']['token'])->toBe('${OPENCLAW_MONEY_ASSISTANT_HOOK_TOKEN}')
        ->and($openClawService)
        ->toContain('EnvironmentFile=/etc/money-assistant/openclaw.env')
        ->and($openClawInstaller)
        ->toContain('money-assistant-openclaw.service.conf')
        ->toContain('systemctl --user daemon-reload')
        ->toContain('systemctl --user restart openclaw-gateway.service')
        ->and($manifest['configContracts']['secretInputs']['paths'])->toContain([
            'path' => 'privateKey',
            'expected' => 'string',
        ])
        ->and($manifest['configSchema']['properties']['privateKey'])->toBe([
            'anyOf' => [
                [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                [
                    'type' => 'object',
                    'required' => ['source', 'provider', 'id'],
                    'properties' => [
                        'source' => [
                            'anyOf' => [
                                ['type' => 'string', 'const' => 'env'],
                                ['type' => 'string', 'const' => 'file'],
                                ['type' => 'string', 'const' => 'exec'],
                            ],
                        ],
                        'provider' => ['type' => 'string'],
                        'id' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
});

test('the credential rotation rehearsal replaces each OpenClaw direction independently without printing secrets', function () {
    $rehearsal = file_get_contents(base_path('rehearse-credential-rotation'));

    expect($rehearsal)
        ->toContain('inbound')
        ->toContain('outbound')
        ->toContain('app:credentials:rewrap')
        ->toContain('openclaw secrets audit --check')
        ->toContain('financial_state_before')
        ->toContain('financial_state_after')
        ->toContain('restore_active_credentials')
        ->not->toContain('set -x');

    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $financialStateBefore = Transaction::query()->findOrFail($transaction->id)->getAttributes();

    expect(Transaction::query()->findOrFail($transaction->id)->getAttributes())
        ->toBe($financialStateBefore);
});

test('the credential rotation rehearsal rejects an outbound token file containing extra lines', function () {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-rotation-'.Str::uuid();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $environmentFile = $temporaryDirectory.'/production.env';
    $openClawEnvironmentFile = $temporaryDirectory.'/openclaw.env';
    $activeHookTokenFile = $temporaryDirectory.'/active-hook-token';
    $candidateHookTokenFile = $temporaryDirectory.'/candidate-hook-token';

    mkdir($fakeBinaryDirectory, 0700, true);

    file_put_contents($activeHookTokenFile, "active-hook-token-value-that-is-long-enough\n");
    file_put_contents($candidateHookTokenFile, "candidate-hook-token-value-that-is-long-enough\nINJECTED=value\n");
    file_put_contents($environmentFile, "OPENCLAW_HOOK_TOKEN_FILE={$activeHookTokenFile}\n");
    file_put_contents($openClawEnvironmentFile, "OPENCLAW_MONEY_ASSISTANT_HOOK_TOKEN=active-hook-token-value-that-is-long-enough\n");
    file_put_contents($fakeBinaryDirectory.'/docker', <<<'SH'
#!/bin/sh
printf '%s\n' 'unchanged-financial-state'
SH);
    chmod($fakeBinaryDirectory.'/docker', 0700);

    try {
        $process = new Process(
            [base_path('rehearse-credential-rotation'), 'outbound', $candidateHookTokenFile],
            base_path(),
            [
                'COMPOSE_FILE' => base_path('compose.production.yaml'),
                'ENVIRONMENT_FILE' => $environmentFile,
                'OPENCLAW_ENVIRONMENT_FILE' => $openClawEnvironmentFile,
                'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('candidate hook token must be one long URL-safe value')
            ->and($process->getOutput().$process->getErrorOutput())
            ->not->toContain('candidate-hook-token-value-that-is-long-enough', 'INJECTED=value')
            ->and(file_get_contents($activeHookTokenFile))
            ->toBe("active-hook-token-value-that-is-long-enough\n")
            ->and(file_get_contents($openClawEnvironmentFile))
            ->toBe("OPENCLAW_MONEY_ASSISTANT_HOOK_TOKEN=active-hook-token-value-that-is-long-enough\n");
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('rotating the OpenClaw inbound signing identity invalidates the old key without changing financial state', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $financialStateBefore = DB::table('transactions')->where('id', $transaction->id)->sole();
    $oldKeyPair = sodium_crypto_sign_keypair();
    $newKeyPair = sodium_crypto_sign_keypair();

    config([
        'services.openclaw.capability.key_id' => 'openclaw-service-old',
        'services.openclaw.capability.public_key' => base64_encode(sodium_crypto_sign_publickey($oldKeyPair)),
        'services.openclaw.capability.agent_id' => 'money-assistant',
        'services.openclaw.capability.account_id' => 'money-assistant-owner',
        'services.openclaw.capability.conversation_id' => 'telegram-owner-123',
        'services.openclaw.capability.owner_sender_id' => 'telegram-owner-123',
    ]);

    $payload = [
        'schema_version' => 1,
        'capability' => 'transaction.read',
        'interaction' => [
            'kind' => 'owner_message',
            'agent_id' => 'money-assistant',
            'account_id' => 'money-assistant-owner',
            'conversation_id' => 'telegram-owner-123',
            'owner_sender_id' => 'telegram-owner-123',
            'message_id' => 'rotation-rehearsal-message',
            'occurred_at' => now()->toIso8601String(),
        ],
        'input' => ['transaction_id' => $transaction->id],
    ];
    $send = function (string $keyId, string $privateKey) use ($payload) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $signature = sodium_crypto_sign_detached(implode("\n", [
            $timestamp,
            $nonce,
            'POST',
            '/api/openclaw/v1/transport',
            hash('sha256', $body),
        ]), $privateKey);

        return $this->call(
            'POST',
            '/api/openclaw/v1/transport',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_MONEY_ASSISTANT_KEY_ID' => $keyId,
                'HTTP_X_MONEY_ASSISTANT_TIMESTAMP' => $timestamp,
                'HTTP_X_MONEY_ASSISTANT_NONCE' => $nonce,
                'HTTP_X_MONEY_ASSISTANT_SIGNATURE' => base64_encode($signature),
            ],
            content: $body,
        );
    };

    $send('openclaw-service-old', sodium_crypto_sign_secretkey($oldKeyPair))->assertSuccessful();

    config([
        'services.openclaw.capability.key_id' => 'openclaw-service-new',
        'services.openclaw.capability.public_key' => base64_encode(sodium_crypto_sign_publickey($newKeyPair)),
    ]);

    $send('openclaw-service-old', sodium_crypto_sign_secretkey($oldKeyPair))->assertUnauthorized();
    $send('openclaw-service-new', sodium_crypto_sign_secretkey($newKeyPair))->assertSuccessful();

    expect(DB::table('transactions')->where('id', $transaction->id)->sole())->toEqual($financialStateBefore)
        ->and(OpenClawAuditEvent::query()->get()->toJson())
        ->not->toContain(
            base64_encode(sodium_crypto_sign_secretkey($oldKeyPair)),
            base64_encode(sodium_crypto_sign_secretkey($newKeyPair)),
        );
});

test('rotating the OpenClaw outbound hook token changes only the bearer credential', function () {
    CarbonImmutable::setTestNow('2026-08-01 12:00:00 UTC');
    Http::fake();
    $transaction = Transaction::factory()->create();
    $financialStateBefore = DB::table('transactions')->where('id', $transaction->id)->sole();

    (new HttpOpenClawHook(
        'http://127.0.0.1:19789/hooks/money-assistant',
        'old-outbound-hook-token',
    ))->dispatch((string) Str::uuid(), 'credential.rotation.probe', now());

    (new HttpOpenClawHook(
        'http://127.0.0.1:19789/hooks/money-assistant',
        'new-outbound-hook-token',
    ))->dispatch((string) Str::uuid(), 'credential.rotation.probe', now());

    $requests = Http::recorded();

    expect($requests)->toHaveCount(2)
        ->and($requests[0][0]->hasHeader('Authorization', 'Bearer old-outbound-hook-token'))->toBeTrue()
        ->and($requests[1][0]->hasHeader('Authorization', 'Bearer new-outbound-hook-token'))->toBeTrue()
        ->and(DB::table('transactions')->where('id', $transaction->id)->sole())
        ->toEqual($financialStateBefore);
});

test('the rotation fingerprint ignores credentials and changes with financial state', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create(['amount_minor' => 12_345]);
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();

    Artisan::call('app:financial-state:fingerprint');
    $beforeCredentialRotation = trim(Artisan::output());

    $connection->update([
        'access_token' => 'rotated-access-token',
        'refresh_token' => 'rotated-refresh-token',
    ]);

    Artisan::call('app:financial-state:fingerprint');
    $afterCredentialRotation = trim(Artisan::output());

    $transaction->update(['amount_minor' => 54_321]);

    Artisan::call('app:financial-state:fingerprint');
    $afterFinancialChange = trim(Artisan::output());

    expect($afterCredentialRotation)->toBe($beforeCredentialRotation)
        ->and($afterFinancialChange)->not->toBe($beforeCredentialRotation)
        ->and($afterFinancialChange)->toMatch('/^[a-f0-9]{64}$/');
});
