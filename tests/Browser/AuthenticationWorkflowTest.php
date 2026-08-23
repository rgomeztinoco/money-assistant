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

function disableBrowserPasskeySupport(PendingAwaitablePage $page): void
{
    $page->page()->context()->addInitScript(<<<'JS'
        Object.defineProperty(globalThis, 'PublicKeyCredential', {
            configurable: true,
            value: undefined,
        });
    JS);

    $page->script('window.location.reload()');
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

    $page
        ->assertTitle('Sign in - Money Assistant')
        ->assertSee('Money Assistant')
        ->assertSee('Owner sign in')
        ->assertSee('Sign in with a passkey')
        ->assertSee('Recovery password fallback');

    recoverAccessWithPassword($page, $owner);

    $page->assertPathIs('/');

    markBrowserSessionAsPasswordConfirmed($owner);
    $page->script('window.location.assign("/settings/security")');

    $page
        ->assertPathIs('/settings/security')
        ->assertSee('Update recovery password')
        ->press('Add passkey')
        ->type('Passkey name', 'Browser test passkey')
        ->press('Register passkey')
        ->assertSee('Browser test passkey');

    $credentials = browserPasskeyCredentials($page);

    expect($credentials)->toHaveCount(1);

    $page
        ->click('[data-test="sidebar-menu-button"]')
        ->click('[data-test="logout-button"]')
        ->assertPathIs('/login');

    $page->page()
        ->getByText('Sign in with a passkey', exact: true)
        ->click(['noWaitAfter' => true]);

    $page
        ->assertPathIs('/')
        ->assertSee('Home')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner can recover access using the recovery password', function () {
    $owner = User::factory()->create();
    $page = visit('/login');

    recoverAccessWithPassword($page, $owner);

    $page
        ->assertPathIs('/')
        ->assertSee('Home')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a browser without passkey support offers only recovery password sign-in', function () {
    $owner = User::factory()->create();
    $page = visit('/login');

    disableBrowserPasskeySupport($page);

    $page
        ->assertTitle('Sign in - Money Assistant')
        ->assertSee('Money Assistant')
        ->assertSee('Recovery password')
        ->assertSee('Sign in with recovery password')
        ->assertDontSee('Sign in with a passkey')
        ->assertDontSee('Recovery password fallback');

    recoverAccessWithPassword($page, $owner);

    $page
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $page->script('window.location.assign("/user/confirm-password")');

    $page
        ->assertPathIs('/user/confirm-password')
        ->assertSee('Confirm your identity to continue in this secure area.')
        ->assertSee('Recovery password')
        ->assertDontSee('Confirm with passkey')
        ->assertDontSee('Use a passkey when available')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner session expires after two hours of inactivity', function () {
    $owner = User::factory()->create();
    $page = visit('/login');

    recoverAccessWithPassword($page, $owner);

    $page->assertPathIs('/');

    $this->travel(121)->minutes();

    resetLaravelBrowserRequestState();

    $page->script('window.location.assign("/settings/appearance")');

    $page
        ->assertPathIs('/login')
        ->assertSee('Owner sign in')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('sensitive operations reject stale authentication', function () {
    $owner = User::factory()->create();
    $page = visit('/login');

    recoverAccessWithPassword($page, $owner);

    $page
        ->assertPathIs('/')
        ->script('window.location.assign("/settings/security")');

    $page
        ->assertPathIs('/user/confirm-password')
        ->assertNoJavaScriptErrors()
        ->assertSee('Confirm with passkey')
        ->assertNoConsoleLogs();
});
