<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Context;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

function configurePasskeysForBrowser(PendingAwaitablePage $page): void
{
    $origin = parse_url($page->url(), PHP_URL_SCHEME).'://'.parse_url($page->url(), PHP_URL_HOST).':'.parse_url($page->url(), PHP_URL_PORT);
    $relyingPartyId = parse_url($origin, PHP_URL_HOST);

    config([
        'fortify.passkeys.allowed_origins' => [$origin],
        'fortify.passkeys.relying_party_id' => $relyingPartyId,
        'passkeys.allowed_origins' => [$origin],
        'passkeys.relying_party_id' => $relyingPartyId,
    ]);
}

function installVirtualPasskeyAuthenticator(PendingAwaitablePage $page): void
{
    iterator_to_array(Client::instance()->execute(playwrightBrowserContextGuid($page), 'credentialsInstall'));
}

/**
 * @return array<int, array<string, mixed>>
 */
function browserPasskeyCredentials(PendingAwaitablePage $page): array
{
    foreach (Client::instance()->execute(playwrightBrowserContextGuid($page), 'credentialsGet') as $message) {
        if (isset($message['result']['credentials'])) {
            return $message['result']['credentials'];
        }
    }

    return [];
}

function playwrightBrowserContextGuid(PendingAwaitablePage $page): string
{
    $context = $page->page()->context();

    return (new ReflectionProperty(Context::class, 'guid'))->getValue($context);
}

function resetLaravelBrowserRequestState(): void
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
}

function markBrowserSessionAsPasswordConfirmed(User $owner): void
{
    $session = DB::table('sessions')
        ->where('user_id', $owner->getKey())
        ->first(['id', 'payload']);

    if ($session === null) {
        throw new RuntimeException('The browser session was not persisted.');
    }

    $payload = json_decode(base64_decode($session->payload), true, flags: JSON_THROW_ON_ERROR);
    data_set($payload, 'auth.password_confirmed_at', now()->unix());

    DB::table('sessions')
        ->where('id', $session->id)
        ->update(['payload' => base64_encode(json_encode($payload, flags: JSON_THROW_ON_ERROR))]);

    resetLaravelBrowserRequestState();
}

function recoverAccessWithPassword(PendingAwaitablePage $page, User $owner): void
{
    $page
        ->type('#email', $owner->email)
        ->type('#password', 'password')
        ->click('[data-test="login-button"]');
}

test('the owner can register a passkey and use it for normal sign-in', function () {
    $owner = User::factory()->create();
    $page = visit('/login');

    configurePasskeysForBrowser($page);
    installVirtualPasskeyAuthenticator($page);

    recoverAccessWithPassword($page, $owner);

    $page->assertPathIs('/dashboard');

    markBrowserSessionAsPasswordConfirmed($owner);
    $page->script('window.location.assign("/settings/security")');

    $page
        ->assertPathIs('/settings/security')
        ->press('Add passkey')
        ->type('Passkey name', 'Browser test passkey')
        ->press('Register passkey')
        ->assertSee('Browser test passkey');

    $credentials = browserPasskeyCredentials($page);

    expect($credentials)->toHaveCount(1);

    $page
        ->click('[data-test="sidebar-menu-button"]')
        ->click('[data-test="logout-button"]')
        ->assertPathIs('/');

    $page->script('window.location.assign("/login")');

    $page->assertPathIs('/login');

    $page->page()
        ->getByText('Sign in with a passkey', exact: true)
        ->click(['noWaitAfter' => true]);

    $page
        ->assertPathIs('/dashboard')
        ->assertSee('Dashboard')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner can recover access using the password and an offline recovery code', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $page = visit('/login');

    recoverAccessWithPassword($page, $owner);

    $page
        ->assertPathIs('/two-factor-challenge')
        ->press('login using a recovery code')
        ->type('[name="recovery_code"]', 'recovery-code-1')
        ->press('Continue')
        ->assertPathIs('/dashboard')
        ->assertSee('Dashboard')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner session expires after two hours of inactivity', function () {
    $owner = User::factory()->create();
    $page = visit('/login');

    recoverAccessWithPassword($page, $owner);

    $page->assertPathIs('/dashboard');

    $this->travel(121)->minutes();

    resetLaravelBrowserRequestState();

    $page->script('window.location.assign("/settings/appearance")');

    $page
        ->assertPathIs('/login')
        ->assertSee('Log in to your account')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('sensitive operations reject stale authentication', function () {
    $owner = User::factory()->create();
    $page = visit('/login');

    recoverAccessWithPassword($page, $owner);

    $page
        ->assertPathIs('/dashboard')
        ->script('window.location.assign("/settings/security")');

    $page
        ->assertPathIs('/user/confirm-password')
        ->assertNoJavaScriptErrors()
        ->assertSee('Confirm with passkey')
        ->script('window.location.assign("/settings/profile")');

    $page
        ->assertPathIs('/settings/profile')
        ->click('[data-test="delete-user-button"]')
        ->click('[data-test="confirm-delete-user-button"]')
        ->assertPathIs('/confirm-passkey')
        ->assertSee('Confirm with passkey')
        ->assertDontSee('Confirm password')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
