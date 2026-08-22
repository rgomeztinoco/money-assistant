<?php

use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\SyntheticPdf;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner selects a statement once resolves exceptions and revisits the confirmed import', function () {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText((string) file_get_contents(
        base_path('tests/Fixtures/Statements/interbank.txt'),
    ));
    [$server, $applicationUrl] = startBrowserApplication();

    try {
        $page = visit($applicationUrl.'/login');
        $page
            ->type('#email', $owner->email)
            ->type('#password', 'password')
            ->click('[data-test="login-button"]')
            ->assertPathIs('/dashboard')
            ->click('Transactions')
            ->assertPathIs('/transactions')
            ->click('Import statement')
            ->assertPathIs('/statement-imports/create');
        selectPdfInBrowser($page, '#preview-statement', $pdf);
        expect($page->script("document.querySelector('#preview-statement').files.length"))->toBe(1);
        $page->press('Preview statement');
        $page
            ->assertSee('INTERBANK')
            ->assertSee('Reconciled')
            ->assertSee('Statement checks and information')
            ->click('[data-test="statement-checks"]')
            ->assertSee('Minimum payment')
            ->click('[data-test="statement-checks"]')
            ->assertDontSee('Minimum payment')
            ->assertSee('1 unresolved')
            ->assertSee('5 classified movements')
            ->assertButtonDisabled('Confirm Statement Import');
        expect($page->value('select[aria-label="Classification for Mercado Pago"]'))
            ->toBe('needs_classification');
        expect($page->script("document.querySelector('#preview-statement').files.length"))->toBe(1);

        $page->select(
            'select[aria-label="Classification for Mercado Pago"]',
            'transfer',
        );
        $page
            ->assertSee('0 unresolved')
            ->assertButtonEnabled('Confirm Statement Import');
        expect($page->script("document.querySelector('#preview-statement').files.length"))->toBe(1);
        $page
            ->press('Confirm Statement Import')
            ->assertPathBeginsWith('/statement-imports/')
            ->assertSee('Statement Movements')
            ->assertSee('Source reconciliation')
            ->assertSee('payment total usd')
            ->assertSee('Mercado Pago')
            ->assertSee('Transfer or payment');

        $page->resize(390, 844);
        expect($page->script("document.querySelector('[data-test=statement-movements]').scrollWidth <= document.querySelector('[data-test=statement-movements]').clientWidth"))
            ->toBeTrue();

        $page
            ->click('Statement Imports')
            ->assertPathIs('/statement-imports')
            ->assertSee('Interbank American Express');
        expect($page->script("document.querySelector('[data-test=statement-import-list]').scrollWidth <= document.querySelector('[data-test=statement-import-list]').clientWidth"))
            ->toBeTrue();
        $page
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();

        expect(StatementImport::query()->count())->toBe(1)
            ->and(StatementMovement::query()->count())->toBe(6)
            ->and(StatementMovement::query()->whereNull('transaction_id')->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(6);
    } finally {
        $server->stop();
    }
});

test('BCP WARDA rows preview and confirm as Savings', function () {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText((string) file_get_contents(
        base_path('tests/Fixtures/Statements/bcp.txt'),
    ));
    [$server, $applicationUrl] = startBrowserApplication();

    try {
        $page = visit($applicationUrl.'/login');
        $page
            ->type('#email', $owner->email)
            ->type('#password', 'password')
            ->click('[data-test="login-button"]')
            ->assertPathIs('/dashboard')
            ->navigate($applicationUrl.'/statement-imports/create');
        selectPdfInBrowser($page, '#preview-statement', $pdf);
        $page
            ->press('Preview statement')
            ->assertSee('BCP')
            ->assertDontSee('Category for Savings movements');

        expect($page->value('#movement-0-classification'))->toBe('savings');

        $page->select(
            'select[aria-label="Classification for DEPOSITO"]',
            'transfer',
        );
        $page
            ->press('Confirm Statement Import')
            ->assertPathBeginsWith('/statement-imports/')
            ->assertSee('Savings deposits')
            ->assertSee('Savings withdrawals')
            ->assertSee('Net savings')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();

        expect(StatementMovement::query()->where('classification', 'savings')->count())->toBe(2)
            ->and(Transaction::query()->where('kind', 'transfer')->where('transfer_purpose', 'savings')->count())->toBe(2)
            ->and(Transaction::query()->whereNotNull('category_id')->doesntExist())->toBeTrue();
    } finally {
        $server->stop();
    }
})->depends('the owner selects a statement once resolves exceptions and revisits the confirmed import');

test('an abandoned preview remains transient and is editable on a mobile viewport', function () {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText((string) file_get_contents(
        base_path('tests/Fixtures/Statements/interbank.txt'),
    ));
    [$server, $applicationUrl] = startBrowserApplication();

    try {
        $page = visit($applicationUrl.'/login');
        $page
            ->type('#email', $owner->email)
            ->type('#password', 'password')
            ->click('[data-test="login-button"]')
            ->assertPathIs('/dashboard')
            ->navigate($applicationUrl.'/statement-imports/create')
            ->resize(390, 844);

        selectPdfInBrowser($page, '#preview-statement', $pdf);
        $page
            ->press('Preview statement')
            ->assertSee('Reconciled')
            ->assertSee('1 unresolved')
            ->assertVisible(
                'select[aria-label="Classification for Mercado Pago"]',
            )
            ->assertNoJavaScriptErrors();

        expect($page->script('document.documentElement.scrollWidth <= document.documentElement.clientWidth'))
            ->toBeTrue()
            ->and(StatementImport::query()->doesntExist())->toBeTrue();

        $page
            ->refresh()
            ->assertDontSee('Reconciled');

        expect(StatementImport::query()->doesntExist())->toBeTrue();

        selectPdfInBrowser($page, '#preview-statement', $pdf);
        $page
            ->press('Preview statement')
            ->assertSee('Reconciled')
            ->click('Statement Imports')
            ->assertPathIs('/statement-imports')
            ->back()
            ->assertPathIs('/statement-imports/create')
            ->assertDontSee('Reconciled');

        expect(StatementImport::query()->doesntExist())->toBeTrue();

        selectPdfInBrowser($page, '#preview-statement', $pdf);
        $page
            ->press('Preview statement')
            ->assertSee('Reconciled')
            ->resize(1280, 720)
            ->click('[data-test="sidebar-menu-button"]')
            ->click('[data-test="logout-button"]')
            ->assertPathIs('/login')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();

        expect(StatementImport::query()->doesntExist())->toBeTrue();
    } finally {
        $server->stop();
    }
})->depends('BCP WARDA rows preview and confirm as Savings');

/**
 * @return array{InvokedProcess, string}
 */
function startBrowserApplication(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException("Unable to reserve a browser application port: {$errorCode} {$errorMessage}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    if ($address === false) {
        throw new RuntimeException('Unable to determine the browser application port.');
    }

    $port = (int) str($address)->afterLast(':')->toString();
    $applicationUrl = "http://127.0.0.1:{$port}";
    $server = Process::path(base_path())
        ->env([
            'APP_ENV' => 'testing',
            'APP_URL' => $applicationUrl,
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'SESSION_DRIVER' => 'database',
        ])
        ->timeout(120)
        ->start([
            PHP_BINARY,
            'artisan',
            'serve',
            '--host=127.0.0.1',
            "--port={$port}",
            '--no-reload',
            '--no-interaction',
        ]);

    $server->waitUntil(
        fn (string $type, string $output): bool => str_contains($output, 'Server running on'),
    );

    return [$server, $applicationUrl];
}

function selectPdfInBrowser(mixed $page, string $selector, string $pdf): void
{
    $base64 = base64_encode($pdf);
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);

    $page->script(
        <<<JS
        (() => {
            const input = document.querySelector({$selector});
            const binary = atob('{$base64}');
            const bytes = Uint8Array.from(binary, character => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'statement.pdf', { type: 'application/pdf' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })()
        JS,
    );
}
