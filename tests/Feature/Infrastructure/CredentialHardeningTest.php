<?php

use App\Integrations\OpenClaw\HttpOpenClawHook;
use App\Models\GmailConnection;
use App\Models\OpenClawAuditEvent;
use App\Models\Reminder;
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

/**
 * @return array<string, string>
 */
function credentialRotationRehearsalFixture(): array
{
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-rotation-'.Str::uuid();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $oldKeyPair = sodium_crypto_sign_keypair();
    $candidateKeyPair = sodium_crypto_sign_keypair();
    $paths = [
        'temporaryDirectory' => $temporaryDirectory,
        'fakeBinaryDirectory' => $fakeBinaryDirectory,
        'commandLog' => $temporaryDirectory.'/commands.log',
        'environmentFile' => $temporaryDirectory.'/production.env',
        'openClawEnvironmentFile' => $temporaryDirectory.'/openclaw.env',
        'activePrivateKeyFile' => $temporaryDirectory.'/active-private-key',
        'activePublicKeyFile' => $temporaryDirectory.'/active-public-key',
        'activeHookTokenFile' => $temporaryDirectory.'/active-hook-token',
        'activeApplicationKeyFile' => $temporaryDirectory.'/active-application-key',
        'activePreviousKeysFile' => $temporaryDirectory.'/active-previous-keys',
        'candidatePrivateKeyFile' => $temporaryDirectory.'/candidate-private-key',
        'candidatePublicKeyFile' => $temporaryDirectory.'/candidate-public-key',
        'candidateHookTokenFile' => $temporaryDirectory.'/candidate-hook-token',
        'candidateApplicationKeyFile' => $temporaryDirectory.'/candidate-application-key',
        'candidatePreviousKeysFile' => $temporaryDirectory.'/candidate-previous-keys',
        'candidateKeyId' => 'openclaw-service-rotated',
        'candidateHookToken' => 'rotated-hook-token-value-that-is-long-enough',
        'candidateApplicationKey' => 'base64:rotated-application-key',
        'candidatePreviousKeys' => 'base64:previous-application-key',
    ];

    mkdir($fakeBinaryDirectory, 0700, true);

    file_put_contents($paths['activePrivateKeyFile'], base64_encode(sodium_crypto_sign_secretkey($oldKeyPair))."\n");
    file_put_contents($paths['activePublicKeyFile'], base64_encode(sodium_crypto_sign_publickey($oldKeyPair))."\n");
    file_put_contents($paths['activeHookTokenFile'], "active-hook-token-value-that-is-long-enough\n");
    file_put_contents($paths['activeApplicationKeyFile'], "base64:active-application-key\n");
    file_put_contents($paths['activePreviousKeysFile'], "base64:older-application-key\n");
    file_put_contents($paths['candidatePrivateKeyFile'], base64_encode(sodium_crypto_sign_secretkey($candidateKeyPair))."\n");
    file_put_contents($paths['candidatePublicKeyFile'], base64_encode(sodium_crypto_sign_publickey($candidateKeyPair))."\n");
    file_put_contents($paths['candidateHookTokenFile'], $paths['candidateHookToken']."\n");
    file_put_contents($paths['candidateApplicationKeyFile'], $paths['candidateApplicationKey']."\n");
    file_put_contents($paths['candidatePreviousKeysFile'], $paths['candidatePreviousKeys']."\n");
    file_put_contents($paths['environmentFile'], implode("\n", [
        "APP_KEY_FILE={$paths['activeApplicationKeyFile']}",
        "APP_PREVIOUS_KEYS_FILE={$paths['activePreviousKeysFile']}",
        "OPENCLAW_CAPABILITY_PUBLIC_KEY_FILE={$paths['activePublicKeyFile']}",
        'OPENCLAW_CAPABILITY_KEY_ID=openclaw-service-active',
        "OPENCLAW_HOOK_TOKEN_FILE={$paths['activeHookTokenFile']}",
        'OPENCLAW_HOOK_URL=http://127.0.0.1:19789/hooks/money-assistant',
        '',
    ]));
    file_put_contents($paths['openClawEnvironmentFile'], implode("\n", [
        'OPENCLAW_MONEY_ASSISTANT_KEY_ID=openclaw-service-active',
        'OPENCLAW_MONEY_ASSISTANT_HOOK_TOKEN=active-hook-token-value-that-is-long-enough',
        '',
    ]));
    file_put_contents($fakeBinaryDirectory.'/docker', <<<'SH'
#!/bin/sh
printf 'docker %s\n' "$*" >> "$ROTATION_COMMAND_LOG"
case "$*" in
    *app:financial-state:fingerprint*) printf '%s\n' 'unchanged-financial-state' ;;
    *force-recreate*web*worker*scheduler*) [ "${APPLICATION_ROTATION_FAILURE:-false}" = false ] ;;
esac
SH);
    file_put_contents($fakeBinaryDirectory.'/systemctl', <<<'SH'
#!/bin/sh
printf 'systemctl %s\n' "$*" >> "$ROTATION_COMMAND_LOG"
SH);
    file_put_contents($fakeBinaryDirectory.'/openclaw', <<<'SH'
#!/bin/sh
printf 'openclaw %s\n' "$*" >> "$ROTATION_COMMAND_LOG"
SH);
    file_put_contents($fakeBinaryDirectory.'/curl', <<<'SH'
#!/bin/sh
printf 'curl %s\n' "$*" >> "$ROTATION_COMMAND_LOG"
case "$*" in
    *inbound-probe-curl*) printf '%s' "${INBOUND_PROBE_STATUS:-422}" ;;
    *outbound-probe-curl*) [ "${OUTBOUND_PROBE_FAILURE:-false}" = false ] ;;
esac
SH);

    foreach (['docker', 'systemctl', 'openclaw', 'curl'] as $binary) {
        chmod($fakeBinaryDirectory.'/'.$binary, 0700);
    }

    return $paths;
}

/**
 * @param  array<string, string>  $fixture
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function runCredentialRotationRehearsal(array $fixture, array $arguments, array $environment = []): Process
{
    $process = new Process(
        [base_path('rehearse-credential-rotation'), ...$arguments],
        base_path(),
        array_merge([
            'COMPOSE_FILE' => base_path('compose.production.yaml'),
            'ENVIRONMENT_FILE' => $fixture['environmentFile'],
            'OPENCLAW_ENVIRONMENT_FILE' => $fixture['openClawEnvironmentFile'],
            'OPENCLAW_PRIVATE_KEY_FILE' => $fixture['activePrivateKeyFile'],
            'OPENCLAW_SERVICE' => 'openclaw-gateway.service',
            'ROTATION_COMMAND_LOG' => $fixture['commandLog'],
            'PATH' => $fixture['fakeBinaryDirectory'].':'.getenv('PATH'),
        ], $environment),
    );
    $process->run();

    return $process;
}

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

test('the credential rotation rehearsal verifies every direction without printing secrets', function () {
    $fixture = credentialRotationRehearsalFixture();

    try {
        $inbound = runCredentialRotationRehearsal($fixture, [
            'inbound',
            $fixture['candidatePrivateKeyFile'],
            $fixture['candidatePublicKeyFile'],
            $fixture['candidateKeyId'],
        ]);
        $outbound = runCredentialRotationRehearsal($fixture, [
            'outbound',
            $fixture['candidateHookTokenFile'],
        ]);
        $application = runCredentialRotationRehearsal($fixture, [
            'application',
            $fixture['candidateApplicationKeyFile'],
            $fixture['candidatePreviousKeysFile'],
        ]);
        $processOutput = implode("\n", [
            $inbound->getOutput(),
            $inbound->getErrorOutput(),
            $outbound->getOutput(),
            $outbound->getErrorOutput(),
            $application->getOutput(),
            $application->getErrorOutput(),
        ]);
        $commandLog = file_get_contents($fixture['commandLog']);

        expect($inbound->getExitCode())->toBe(0)
            ->and($outbound->getExitCode())->toBe(0)
            ->and($application->getExitCode())->toBe(0)
            ->and(file_get_contents($fixture['activePrivateKeyFile']))
            ->toBe(file_get_contents($fixture['candidatePrivateKeyFile']))
            ->and(file_get_contents($fixture['activePublicKeyFile']))
            ->toBe(file_get_contents($fixture['candidatePublicKeyFile']))
            ->and(file_get_contents($fixture['activeHookTokenFile']))
            ->toBe(file_get_contents($fixture['candidateHookTokenFile']))
            ->and(file_get_contents($fixture['activeApplicationKeyFile']))
            ->toBe(file_get_contents($fixture['candidateApplicationKeyFile']))
            ->and(file_get_contents($fixture['activePreviousKeysFile']))
            ->toBe(file_get_contents($fixture['candidatePreviousKeysFile']))
            ->and(file_get_contents($fixture['environmentFile']))
            ->toContain("OPENCLAW_CAPABILITY_KEY_ID={$fixture['candidateKeyId']}")
            ->and(file_get_contents($fixture['openClawEnvironmentFile']))
            ->toContain("OPENCLAW_MONEY_ASSISTANT_KEY_ID={$fixture['candidateKeyId']}")
            ->toContain("OPENCLAW_MONEY_ASSISTANT_HOOK_TOKEN={$fixture['candidateHookToken']}")
            ->and($commandLog)
            ->toContain('openclaw secrets audit --check')
            ->toContain('force-recreate web')
            ->toContain('force-recreate worker')
            ->toContain('app:credentials:rewrap')
            ->toContain('inbound-probe-curl')
            ->toContain('outbound-probe-curl')
            ->and($processOutput)
            ->not->toContain(
                file_get_contents($fixture['candidatePrivateKeyFile']),
                $fixture['candidateHookToken'],
                $fixture['candidateApplicationKey'],
            );
    } finally {
        (new Filesystem)->deleteDirectory($fixture['temporaryDirectory']);
    }
});

test('the credential rotation rehearsal restores inbound credentials after a failed live probe', function () {
    $fixture = credentialRotationRehearsalFixture();
    $activePrivateKey = file_get_contents($fixture['activePrivateKeyFile']);
    $activePublicKey = file_get_contents($fixture['activePublicKeyFile']);
    $activeEnvironment = file_get_contents($fixture['environmentFile']);
    $activeOpenClawEnvironment = file_get_contents($fixture['openClawEnvironmentFile']);

    try {
        $process = runCredentialRotationRehearsal($fixture, [
            'inbound',
            $fixture['candidatePrivateKeyFile'],
            $fixture['candidatePublicKeyFile'],
            $fixture['candidateKeyId'],
        ], ['INBOUND_PROBE_STATUS' => '401']);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('rotated inbound credential was not authenticated')
            ->and($process->getOutput().$process->getErrorOutput())
            ->not->toContain(file_get_contents($fixture['candidatePrivateKeyFile']))
            ->and(file_get_contents($fixture['activePrivateKeyFile']))->toBe($activePrivateKey)
            ->and(file_get_contents($fixture['activePublicKeyFile']))->toBe($activePublicKey)
            ->and(file_get_contents($fixture['environmentFile']))->toBe($activeEnvironment)
            ->and(file_get_contents($fixture['openClawEnvironmentFile']))->toBe($activeOpenClawEnvironment)
            ->and(file_get_contents($fixture['commandLog']))
            ->toContain('inbound-probe-curl')
            ->toContain('systemctl --user restart openclaw-gateway.service');
    } finally {
        (new Filesystem)->deleteDirectory($fixture['temporaryDirectory']);
    }
});

test('the credential rotation rehearsal restores the outbound credential after a failed live probe', function () {
    $fixture = credentialRotationRehearsalFixture();
    $activeHookToken = file_get_contents($fixture['activeHookTokenFile']);
    $activeOpenClawEnvironment = file_get_contents($fixture['openClawEnvironmentFile']);

    try {
        $process = runCredentialRotationRehearsal($fixture, [
            'outbound',
            $fixture['candidateHookTokenFile'],
        ], ['OUTBOUND_PROBE_FAILURE' => 'true']);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('rotated outbound credential was rejected')
            ->and($process->getOutput().$process->getErrorOutput())
            ->not->toContain($fixture['candidateHookToken'])
            ->and(file_get_contents($fixture['activeHookTokenFile']))->toBe($activeHookToken)
            ->and(file_get_contents($fixture['openClawEnvironmentFile']))->toBe($activeOpenClawEnvironment)
            ->and(file_get_contents($fixture['commandLog']))
            ->toContain('outbound-probe-curl')
            ->toContain('force-recreate worker');
    } finally {
        (new Filesystem)->deleteDirectory($fixture['temporaryDirectory']);
    }
});

test('the credential rotation rehearsal restores application keys after a failed service restart', function () {
    $fixture = credentialRotationRehearsalFixture();
    $activeApplicationKey = file_get_contents($fixture['activeApplicationKeyFile']);
    $activePreviousKeys = file_get_contents($fixture['activePreviousKeysFile']);

    try {
        $process = runCredentialRotationRehearsal($fixture, [
            'application',
            $fixture['candidateApplicationKeyFile'],
            $fixture['candidatePreviousKeysFile'],
        ], ['APPLICATION_ROTATION_FAILURE' => 'true']);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput().$process->getErrorOutput())
            ->not->toContain(
                $fixture['candidateApplicationKey'],
                $fixture['candidatePreviousKeys'],
            )
            ->and(file_get_contents($fixture['activeApplicationKeyFile']))->toBe($activeApplicationKey)
            ->and(file_get_contents($fixture['activePreviousKeysFile']))->toBe($activePreviousKeys)
            ->and(file_get_contents($fixture['commandLog']))
            ->toContain('force-recreate web worker scheduler')
            ->not->toContain('app:credentials:rewrap');
    } finally {
        (new Filesystem)->deleteDirectory($fixture['temporaryDirectory']);
    }
});

test('the credential rotation rehearsal rejects an unchanged candidate without printing it', function () {
    $fixture = credentialRotationRehearsalFixture();
    $activeHookToken = file_get_contents($fixture['activeHookTokenFile']);
    file_put_contents($fixture['candidateHookTokenFile'], $activeHookToken);

    try {
        $process = runCredentialRotationRehearsal($fixture, [
            'outbound',
            $fixture['candidateHookTokenFile'],
        ]);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('candidate and active secret values must differ')
            ->and($process->getOutput().$process->getErrorOutput())->not->toContain(trim($activeHookToken))
            ->and(file_get_contents($fixture['activeHookTokenFile']))->toBe($activeHookToken);
    } finally {
        (new Filesystem)->deleteDirectory($fixture['temporaryDirectory']);
    }
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

test('the rotation fingerprint ignores credentials and covers the complete financial export', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create(['amount_minor' => 12_345]);
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();

    Artisan::call('app:financial-state:fingerprint');
    $beforeCredentialRotation = trim(Artisan::output());

    $connection->timestamps = false;
    $connection->update([
        'access_token' => 'rotated-access-token',
        'refresh_token' => 'rotated-refresh-token',
    ]);

    Artisan::call('app:financial-state:fingerprint');
    $afterCredentialRotation = trim(Artisan::output());

    Reminder::factory()->for($owner, 'owner')->create([
        'subject' => 'Review the monthly financial plan',
    ]);

    Artisan::call('app:financial-state:fingerprint');
    $afterFinancialChange = trim(Artisan::output());

    expect($afterCredentialRotation)->toBe($beforeCredentialRotation)
        ->and($afterFinancialChange)->not->toBe($beforeCredentialRotation)
        ->and($afterFinancialChange)->toMatch('/^[a-f0-9]{64}$/');
});
