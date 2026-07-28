<?php

use App\Actions\NotificationIngestion\CompleteGmailAuthorization;
use App\Actions\NotificationIngestion\RefreshGmailConnection;
use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailAccess;
use App\Integrations\Gmail\GmailAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeGmail;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('notification ingestion uses a fake Gmail contract without live network access', function () {
    $owner = User::factory()->create();
    $gmail = new FakeGmail;
    $gmail->authorization = new GmailAuthorization(
        accessToken: 'fake-access-token',
        refreshToken: 'fake-refresh-token',
        accessTokenExpiresAt: now()->addHour(),
        grantedScopes: [Gmail::READ_ONLY_SCOPE],
        accountIdentity: 'receipts@example.test',
    );
    $gmail->access = new GmailAccess(
        accessToken: 'refreshed-fake-access-token',
        accessTokenExpiresAt: now()->addHours(2),
    );
    app()->instance(Gmail::class, $gmail);

    $connection = app(CompleteGmailAuthorization::class)->handle($owner, 'fake-code');
    $connection = app(RefreshGmailConnection::class)->handle($connection);

    expect($gmail->authorizationCodes)->toBe(['fake-code'])
        ->and($gmail->refreshTokens)->toBe(['fake-refresh-token'])
        ->and($connection->access_token)->toBe('refreshed-fake-access-token');

    Http::assertNothingSent();
});
