<?php

use App\Models\GmailConnection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
});
