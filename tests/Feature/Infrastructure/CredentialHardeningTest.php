<?php

use App\Models\GmailConnection;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
    $paths = [
        'temporaryDirectory' => $temporaryDirectory,
        'fakeBinaryDirectory' => $fakeBinaryDirectory,
        'commandLog' => $temporaryDirectory.'/commands.log',
        'environmentFile' => $temporaryDirectory.'/production.env',
        'activeApplicationKeyFile' => $temporaryDirectory.'/active-application-key',
        'activePreviousKeysFile' => $temporaryDirectory.'/active-previous-keys',
        'candidateApplicationKeyFile' => $temporaryDirectory.'/candidate-application-key',
        'candidatePreviousKeysFile' => $temporaryDirectory.'/candidate-previous-keys',
        'candidateApplicationKey' => 'base64:rotated-application-key',
        'candidatePreviousKeys' => 'base64:previous-application-key',
    ];

    mkdir($fakeBinaryDirectory, 0700, true);
    file_put_contents($paths['activeApplicationKeyFile'], "base64:active-application-key\n");
    file_put_contents($paths['activePreviousKeysFile'], "base64:older-application-key\n");
    file_put_contents($paths['candidateApplicationKeyFile'], $paths['candidateApplicationKey']."\n");
    file_put_contents($paths['candidatePreviousKeysFile'], $paths['candidatePreviousKeys']."\n");
    file_put_contents($paths['environmentFile'], implode("\n", [
        "APP_KEY_FILE={$paths['activeApplicationKeyFile']}",
        "APP_PREVIOUS_KEYS_FILE={$paths['activePreviousKeysFile']}",
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
    chmod($fakeBinaryDirectory.'/docker', 0700);

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

    foreach (['spending_notification_references', 'gmail_message_discoveries'] as $table) {
        expect(Schema::getColumnListing($table))
            ->not->toContain(...$forbiddenColumnNames);
    }
});

test('production credentials resolve only through host-managed secret boundaries', function (): void {
    $compose = file_get_contents(base_path('compose.production.yaml'));
    $environment = file_get_contents(base_path('.env.production.example'));
    $entrypoint = file_get_contents(base_path('docker-entrypoint.production'));

    expect($compose)
        ->toContain('APP_PREVIOUS_KEYS_FILE: /run/secrets/application_previous_keys')
        ->toContain('google_gmail_client_secret')
        ->and($environment)
        ->toContain('APP_PREVIOUS_KEYS_FILE=/etc/money-assistant/secrets/application_previous_keys')
        ->and($entrypoint)
        ->toContain('read_optional_secret APP_PREVIOUS_KEYS');

    expect($compose.$environment.$entrypoint)->not->toContain('OPENCLAW_');
});

test('the credential rotation rehearsal changes application keys without printing secrets', function (): void {
    $fixture = credentialRotationRehearsalFixture();

    try {
        $process = runCredentialRotationRehearsal($fixture, [
            'application',
            $fixture['candidateApplicationKeyFile'],
            $fixture['candidatePreviousKeysFile'],
        ]);

        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($fixture['activeApplicationKeyFile']))
            ->toBe(file_get_contents($fixture['candidateApplicationKeyFile']))
            ->and(file_get_contents($fixture['activePreviousKeysFile']))
            ->toBe(file_get_contents($fixture['candidatePreviousKeysFile']))
            ->and(file_get_contents($fixture['commandLog']))
            ->toContain('force-recreate web worker scheduler')
            ->toContain('app:credentials:rewrap')
            ->and($process->getOutput().$process->getErrorOutput())
            ->not->toContain(
                $fixture['candidateApplicationKey'],
                $fixture['candidatePreviousKeys'],
            );
    } finally {
        (new Filesystem)->deleteDirectory($fixture['temporaryDirectory']);
    }
});

test('the credential rotation rehearsal restores application keys after a failed service restart', function (): void {
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

test('the credential rotation rehearsal rejects an unchanged candidate without printing it', function (): void {
    $fixture = credentialRotationRehearsalFixture();
    $activeApplicationKey = file_get_contents($fixture['activeApplicationKeyFile']);
    file_put_contents($fixture['candidateApplicationKeyFile'], $activeApplicationKey);

    try {
        $process = runCredentialRotationRehearsal($fixture, [
            'application',
            $fixture['candidateApplicationKeyFile'],
            $fixture['candidatePreviousKeysFile'],
        ]);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('candidate and active secret values must differ')
            ->and($process->getOutput().$process->getErrorOutput())->not->toContain(trim($activeApplicationKey))
            ->and(file_get_contents($fixture['activeApplicationKeyFile']))->toBe($activeApplicationKey);
    } finally {
        (new Filesystem)->deleteDirectory($fixture['temporaryDirectory']);
    }
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
