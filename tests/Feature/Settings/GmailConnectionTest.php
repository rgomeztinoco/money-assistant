<?php

use App\Actions\NotificationIngestion\RefreshGmailConnection;
use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailAccess;
use App\Integrations\Gmail\GmailAuthorization;
use App\Integrations\Gmail\GmailProfile;
use App\Integrations\Gmail\GmailReauthorizationRequired;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Models\GmailConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeGmail;

beforeEach(function () {
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
});

test('the owner starts a state-bound offline Gmail authorization from settings', function () {
    config()->set('services.gmail', [
        'client_id' => 'google-client-id',
        'client_secret' => 'google-client-secret',
        'redirect_uri' => 'https://money.example.test/settings/connections/gmail/callback',
        'oauth_publishing_status' => 'production',
    ]);
    $owner = User::factory()->create();
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('gmail.authorization.create'))
        ->assertRedirect($gmail->authorizationUrl)
        ->assertSessionHas('gmail_oauth_state');

    expect($gmail->authorizationUrlCalls)->toHaveCount(1)
        ->and($gmail->authorizationUrlCalls[0]['state'])->toHaveLength(64)
        ->and($gmail->authorizationUrlCalls[0]['login_hint'])->toBe($owner->email);
});

test('starting Gmail authorization requires fresh owner authentication', function () {
    $owner = User::factory()->create();
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->get(route('gmail.authorization.create'))
        ->assertRedirect(route('password.confirm'));

    expect($gmail->authorizationUrlCalls)->toBeEmpty();
});

test('Gmail authorization cannot start while the OAuth project is still in Testing', function () {
    config()->set('services.gmail', [
        'client_id' => 'google-client-id',
        'client_secret' => 'google-client-secret',
        'redirect_uri' => 'https://money.example.test/settings/connections/gmail/callback',
        'oauth_publishing_status' => 'testing',
    ]);
    $owner = User::factory()->create();
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('gmail.authorization.create'))
        ->assertServiceUnavailable();

    expect($gmail->authorizationUrlCalls)->toBeEmpty();
});

test('the state-bound callback stores hidden encrypted credentials for the Gmail account', function () {
    CarbonImmutable::setTestNow('2026-07-28 18:30:00 UTC');
    $owner = User::factory()->create();
    $gmail = new FakeGmail;
    $gmail->authorization = new GmailAuthorization(
        accessToken: 'sensitive-access-token',
        refreshToken: 'sensitive-refresh-token',
        accessTokenExpiresAt: now()->addHour(),
        grantedScopes: [Gmail::READ_ONLY_SCOPE],
        accountIdentity: 'receipts@example.test',
        historyId: 'bootstrap-history-100',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->withSession([
            'gmail_oauth_state' => [
                'state' => 'expected-state',
                'user_id' => $owner->id,
            ],
        ])
        ->get(route('gmail.authorization.store', [
            'code' => 'authorization-code',
            'state' => 'expected-state',
        ]))
        ->assertRedirect(route('connections.edit'))
        ->assertSessionMissing('gmail_oauth_state');

    $connection = GmailConnection::query()->sole();
    $stored = DB::table($connection->getTable())->where('id', $connection->id)->sole();

    expect($gmail->authorizationCodes)->toBe(['authorization-code'])
        ->and($connection->user_id)->toBe($owner->id)
        ->and($connection->gmail_account_identity)->toBe('receipts@example.test')
        ->and($connection->access_token)->toBe('sensitive-access-token')
        ->and($connection->refresh_token)->toBe('sensitive-refresh-token')
        ->and($stored->access_token)->not->toBe('sensitive-access-token')
        ->and($stored->refresh_token)->not->toBe('sensitive-refresh-token')
        ->and($connection->connected_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->history_id)->toBe('bootstrap-history-100')
        ->and($connection->initial_sync_completed_at)->toBeNull()
        ->and($connection->last_successful_check_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->reauthorization_required_at)->toBeNull()
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});

test('a mismatched or replayed OAuth state fails closed before exchanging the code', function (string $state) {
    $owner = User::factory()->create();
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->withSession([
            'gmail_oauth_state' => [
                'state' => 'expected-state',
                'user_id' => $owner->id,
            ],
        ])
        ->get(route('gmail.authorization.store', [
            'code' => 'authorization-code',
            'state' => $state,
        ]))
        ->assertStatus(419)
        ->assertSessionMissing('gmail_oauth_state');

    expect($gmail->authorizationCodes)->toBeEmpty()
        ->and(GmailConnection::query()->count())->toBe(0);
})->with([
    'mismatch' => 'wrong-state',
    'replay after state was consumed' => '',
]);

test('a state-bound denial returns safely without exchanging or retaining credentials', function () {
    $owner = User::factory()->create();
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->withSession([
            'gmail_oauth_state' => [
                'state' => 'expected-state',
                'user_id' => $owner->id,
            ],
        ])
        ->get(route('gmail.authorization.store', [
            'error' => 'access_denied',
            'state' => 'expected-state',
        ]))
        ->assertRedirect(route('connections.edit'))
        ->assertSessionMissing('gmail_oauth_state');

    expect($gmail->authorizationCodes)->toBeEmpty()
        ->and(GmailConnection::query()->count())->toBe(0);
});

test('a broader fake Gmail grant is rejected without replacing retained credentials', function () {
    $connection = GmailConnection::factory()->reauthorizationRequired()->create([
        'access_token' => 'retained-access-token',
        'refresh_token' => 'retained-refresh-token',
    ]);
    $gmail = new FakeGmail;
    $gmail->authorization = new GmailAuthorization(
        accessToken: 'broader-access-token',
        refreshToken: 'broader-refresh-token',
        accessTokenExpiresAt: now()->addHour(),
        grantedScopes: [Gmail::READ_ONLY_SCOPE, 'https://mail.google.com/'],
        accountIdentity: $connection->gmail_account_identity,
        historyId: '200',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->withSession([
            'gmail_oauth_state' => [
                'state' => 'expected-state',
                'user_id' => $connection->user_id,
            ],
        ])
        ->get(route('gmail.authorization.store', [
            'code' => 'authorization-code',
            'state' => 'expected-state',
        ]))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    expect($connection->access_token)->toBe('retained-access-token')
        ->and($connection->refresh_token)->toBe('retained-refresh-token')
        ->and($connection->ingestionIsPaused())->toBeTrue();
});

test('terminal refresh rejection pauses ingestion until explicit owner reauthorization', function () {
    CarbonImmutable::setTestNow('2026-07-28 19:00:00 UTC');
    $connection = GmailConnection::factory()->create([
        'access_token' => 'original-access-token',
        'refresh_token' => 'rejected-refresh-token',
    ]);
    $gmail = new FakeGmail;
    $gmail->refreshFailure = new GmailReauthorizationRequired;
    app()->instance(Gmail::class, $gmail);
    $refresh = app(RefreshGmailConnection::class);

    expect(fn () => $refresh->handle($connection))->toThrow(GmailReauthorizationRequired::class);

    $connection->refresh();

    expect($connection->ingestionIsPaused())->toBeTrue()
        ->and($connection->reauthorization_required_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->last_error_code)->toBe('refresh_token_rejected')
        ->and($connection->access_token)->toBe('original-access-token')
        ->and($connection->refresh_token)->toBe('rejected-refresh-token')
        ->and($gmail->refreshTokens)->toBe(['rejected-refresh-token']);

    expect(fn () => $refresh->handle($connection))->toThrow(GmailReauthorizationRequired::class)
        ->and($gmail->refreshTokens)->toBe(['rejected-refresh-token']);
});

test('a successful refresh rotates only the encrypted short-lived Gmail access', function () {
    CarbonImmutable::setTestNow('2026-07-28 19:30:00 UTC');
    $connection = GmailConnection::factory()->create([
        'access_token' => 'original-access-token',
        'refresh_token' => 'offline-refresh-token',
        'access_token_expires_at' => now()->subMinute(),
    ]);
    $gmail = new FakeGmail;
    $gmail->access = new GmailAccess(
        accessToken: 'rotated-access-token',
        accessTokenExpiresAt: now()->addHour(),
    );
    app()->instance(Gmail::class, $gmail);

    app(RefreshGmailConnection::class)->handle($connection);
    $connection->refresh();
    $stored = DB::table($connection->getTable())->where('id', $connection->id)->sole();

    expect($connection->access_token)->toBe('rotated-access-token')
        ->and($stored->access_token)->not->toBe('rotated-access-token')
        ->and($connection->refresh_token)->toBe('offline-refresh-token')
        ->and($connection->access_token_expires_at?->toIso8601String())->toBe('2026-07-28T20:30:00+00:00')
        ->and($connection->reauthorization_required_at)->toBeNull();
});

test('settings reports a token-free connected Gmail health projection', function () {
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'access_token' => 'never-render-this-access-token',
        'refresh_token' => 'never-render-this-refresh-token',
        'last_successful_check_at' => '2026-07-28 18:45:00 UTC',
    ]);

    $response = $this->actingAs($connection->owner)
        ->get(route('connections.edit'));

    $response
        ->assertDontSee('never-render-this-access-token')
        ->assertDontSee('never-render-this-refresh-token')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/connections')
            ->where('gmail.state', 'connected')
            ->where('gmail.account_identity', 'receipts@example.test')
            ->where('gmail.scope', Gmail::READ_ONLY_SCOPE)
            ->where('gmail.last_successful_check_at', '2026-07-28T18:45:00+00:00')
            ->where('gmail.reauthorization_required_at', null)
            ->missing('gmail.access_token')
            ->missing('gmail.refresh_token'));
});

test('Gmail connection routes are private to the authenticated owner', function (string $method, string $route) {
    $request = $this;
    $response = $method === 'post'
        ? $request->post(route($route))
        : $request->get(route($route));

    $response->assertRedirect(route('login'));
})->with([
    'settings' => ['get', 'connections.edit'],
    'authorize' => ['get', 'gmail.authorization.create'],
    'callback' => ['get', 'gmail.authorization.store'],
    'health check' => ['post', 'gmail.connection.check'],
]);

test('settings reports disconnected and explicit reauthorization states', function () {
    CarbonImmutable::setTestNow('2026-07-28 19:45:00 UTC');
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('connections.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('gmail.state', 'disconnected')
            ->where('gmail.account_identity', null)
            ->where('gmail.last_successful_check_at', null));

    GmailConnection::factory()->reauthorizationRequired()->for($owner, 'owner')->create([
        'gmail_account_identity' => 'receipts@example.test',
    ]);

    $this->get(route('connections.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('gmail.state', 'reauthorization_required')
            ->where('gmail.account_identity', 'receipts@example.test')
            ->where('gmail.reauthorization_required_at', now()->toIso8601String()));
});

test('an explicit health check refreshes access and records a successful Gmail profile check', function () {
    CarbonImmutable::setTestNow('2026-07-28 20:00:00 UTC');
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'refresh_token' => 'offline-refresh-token',
        'last_successful_check_at' => now()->subDay(),
    ]);
    $gmail = new FakeGmail;
    $gmail->access = new GmailAccess(
        accessToken: 'checked-access-token',
        accessTokenExpiresAt: now()->addHour(),
    );
    $gmail->profile = new GmailProfile('receipts@example.test', '300');
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->post(route('gmail.connection.check'))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    expect($gmail->refreshTokens)->toBe(['offline-refresh-token'])
        ->and($gmail->profileAccessTokens)->toBe(['checked-access-token'])
        ->and($connection->last_successful_check_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->last_error_code)->toBeNull();
});

test('a transient health-check failure remains visible without pausing ingestion', function () {
    CarbonImmutable::setTestNow('2026-07-28 20:15:00 UTC');
    $connection = GmailConnection::factory()->create([
        'refresh_token' => 'offline-refresh-token',
        'last_successful_check_at' => now()->subDay(),
    ]);
    $gmail = new FakeGmail;
    $gmail->refreshFailure = GmailRequestFailed::refresh();
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->post(route('gmail.connection.check'))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    expect($connection->ingestionIsPaused())->toBeFalse()
        ->and($connection->last_successful_check_at?->toIso8601String())->toBe(now()->subDay()->toIso8601String())
        ->and($connection->last_check_failed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->last_error_code)->toBe('gmail_check_failed');

    $this->get(route('connections.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('gmail.state', 'check_failed')
            ->where('gmail.last_check_failed_at', now()->toIso8601String()));
});

test('a checked Gmail account identity mismatch pauses ingestion for owner reauthorization', function () {
    CarbonImmutable::setTestNow('2026-07-28 20:20:00 UTC');
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'refresh_token' => 'offline-refresh-token',
    ]);
    $gmail = new FakeGmail;
    $gmail->access = new GmailAccess(
        accessToken: 'unexpected-account-access-token',
        accessTokenExpiresAt: now()->addHour(),
    );
    $gmail->profile = new GmailProfile('another-account@example.test', '300');
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->post(route('gmail.connection.check'))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    expect($connection->ingestionIsPaused())->toBeTrue()
        ->and($connection->reauthorization_required_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->last_check_failed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->last_error_code)->toBe('gmail_account_mismatch');

    $this->get(route('connections.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('gmail.state', 'reauthorization_required'));
});

test('explicit reauthorization updates the stable connection and resumes ingestion', function () {
    CarbonImmutable::setTestNow('2026-07-28 20:30:00 UTC');
    $connection = GmailConnection::factory()->reauthorizationRequired()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'connected_at' => now()->subMonth(),
        'reauthorization_required_at' => now()->subHour(),
    ]);
    $gmail = new FakeGmail;
    $gmail->authorization = new GmailAuthorization(
        accessToken: 'replacement-access-token',
        refreshToken: 'replacement-refresh-token',
        accessTokenExpiresAt: now()->addHour(),
        grantedScopes: [Gmail::READ_ONLY_SCOPE],
        accountIdentity: 'receipts@example.test',
        historyId: '400',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->withSession([
            'gmail_oauth_state' => [
                'state' => 'reauthorize-state',
                'user_id' => $connection->user_id,
            ],
        ])
        ->get(route('gmail.authorization.store', [
            'code' => 'reauthorization-code',
            'state' => 'reauthorize-state',
        ]))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    expect(GmailConnection::query()->count())->toBe(1)
        ->and($connection->id)->toBe(GmailConnection::query()->sole()->id)
        ->and($connection->connected_at?->toIso8601String())->toBe(now()->subMonth()->toIso8601String())
        ->and($connection->refresh_token)->toBe('replacement-refresh-token')
        ->and($connection->ingestionIsPaused())->toBeFalse()
        ->and($connection->last_error_code)->toBeNull();
});

test('reauthorization cannot silently replace the dedicated Gmail account identity', function () {
    $connection = GmailConnection::factory()->reauthorizationRequired()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'refresh_token' => 'retained-refresh-token',
    ]);
    $gmail = new FakeGmail;
    $gmail->authorization = new GmailAuthorization(
        accessToken: 'other-access-token',
        refreshToken: 'other-refresh-token',
        accessTokenExpiresAt: now()->addHour(),
        grantedScopes: [Gmail::READ_ONLY_SCOPE],
        accountIdentity: 'another-account@example.test',
        historyId: '500',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->withSession([
            'gmail_oauth_state' => [
                'state' => 'reauthorize-state',
                'user_id' => $connection->user_id,
            ],
        ])
        ->get(route('gmail.authorization.store', [
            'code' => 'reauthorization-code',
            'state' => 'reauthorize-state',
        ]))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    expect($connection->gmail_account_identity)->toBe('receipts@example.test')
        ->and($connection->refresh_token)->toBe('retained-refresh-token')
        ->and($connection->ingestionIsPaused())->toBeTrue();
});
