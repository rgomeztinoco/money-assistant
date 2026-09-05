<?php

use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\SyntheticPdf;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    [$this->browserApplication, $this->browserApplicationUrl] = startBrowserApplication();
});

afterEach(function () {
    $this->browserApplication->stop();
});

test('the owner selects a statement once resolves exceptions and confirms the import', function () {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText((string) file_get_contents(
        base_path('tests/Fixtures/Statements/interbank.txt'),
    ));

    $page = visit($this->browserApplicationUrl.'/login');
    $page
        ->type('#email', $owner->email)
        ->type('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/')
        ->assertSee('Import a recent statement first')
        ->click('Import a recent statement')
        ->assertPathIs('/statement-imports/create');
    selectPdfInBrowser($page, '#preview-statement', $pdf);
    expect($page->script("document.querySelector('#preview-statement').files.length"))->toBe(1);
    $page->press('Upload and check');
    $page
        ->assertSee('INTERBANK')
        ->assertSee('Reconciled')
        ->assertSee('Reconciliation')
        ->assertDontSee('Minimum payment')
        ->assertSee('1 unresolved')
        ->assertDontSee('View all movements')
        ->assertSee('Proposed movements')
        ->assertSee('Affect Net Spending')
        ->assertSee('Outside Net Spending')
        ->assertSee('Unresolved')
        ->assertButtonDisabled('Confirm import');
    expect($page->value('select[aria-label="Classification for Mercado Pago"]'))
        ->toBe('needs_classification')
        ->and($page->script("document.querySelector('[data-test=statement-movement-status-0] [aria-label=\"Needs classification\"]') !== null"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movement-status-0]').closest('td').cellIndex === document.querySelector('[data-test=statement-movement-date-0]').closest('td').cellIndex"))
        ->toBeTrue()
        ->and($page->script("Array.from(document.querySelectorAll('[data-test=statement-movements] thead th')).every((heading) => heading.textContent.trim() !== 'Status')"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movement-0]').classList.contains('bg-destructive/5')"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movements] table') !== null"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movements]').textContent.includes('Statement Movement 1')"))
        ->toBeFalse()
        ->and($page->script("document.querySelector('#instrument-label').closest('[data-slot=card]').querySelector('button[type=submit]') !== null"))
        ->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-test=statement-import-overview] [data-slot=card]').length"))
        ->toBe(1)
        ->and($page->script("document.querySelector('[data-test=statement-import-totals]').children.length"))
        ->toBe(4)
        ->and($page->script("document.querySelector('[data-test=statement-import-totals]').classList.contains('divide-x') && document.querySelector('[data-test=statement-import-totals]').classList.contains('bg-muted/50')"))
        ->toBeTrue()
        ->and($page->script("Math.abs(document.querySelector('[data-test=statement-import-overview]').getBoundingClientRect().height - document.querySelector('[data-test=statement-movements-column]').getBoundingClientRect().height) <= 1"))
        ->toBeTrue();
    $page
        ->hover('[data-test=statement-movement-status-0] [data-status=needs_classification]')
        ->assertSee('Choose what kind of movement this is before confirming.');
    expect($page->script("document.querySelector('#preview-statement').files.length"))->toBe(1);

    $page->select(
        'select[aria-label="Classification for Mercado Pago"]',
        'transfer',
    );
    $page
        ->assertSee('0 unresolved')
        ->assertButtonEnabled('Confirm import');
    expect($page->script("document.querySelector('[data-test=statement-movement-status-0] [aria-label=\"Will be added\"]') !== null"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movement-status-0] [aria-label=\"Outside Net Spending\"]') !== null"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movement-0]').classList.contains('bg-destructive/5')"))
        ->toBeFalse()
        ->and($page->script("new Set(Array.from(document.querySelectorAll('[data-status=created]'), (badge) => badge.className)).size"))
        ->toBe(1)
        ->and($page->script("document.querySelectorAll('[data-status=created]').length > 1"))
        ->toBeTrue();
    $page
        ->click('[data-test="statement-movement-0"] button')
        ->assertVisible('#movement-0-description');
    expect($page->value('#movement-0-description'))->toBe('Mercado Pago');
    $page->press('Close');
    expect($page->script("document.querySelector('#preview-statement').files.length"))->toBe(1);
    $page
        ->press('Confirm import')
        ->wait(1);

    expect($page->script("document.querySelector('[data-slot=alert]')?.textContent.trim() ?? ''"))->toBe('');
    expect($page->script('window.location.pathname'))->not->toBe('/statement-imports/create');
    $page
        ->assertPathBeginsWith('/statement-imports/')
        ->assertSee('Statement Import')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(StatementImport::query()->count())->toBe(1)
        ->and(StatementMovement::query()->count())->toBe(6)
        ->and(StatementMovement::query()->whereNull('transaction_id')->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(6);
});

test('BCP WARDA rows preview and confirm as Savings', function () {
    $owner = User::factory()->create();
    collect(['WARDA', 'Warda savings'])->each(
        fn (string $description) => Transaction::factory()
            ->for($owner, 'owner')
            ->create([
                'occurred_on' => '2026-02-01',
                'amount_minor' => 2000,
                'currency' => 'PEN',
                'kind' => TransactionKind::Transfer,
                'direction' => MovementDirection::Debit,
                'transfer_purpose' => TransferPurpose::Savings,
                'description' => $description,
                'instrument_label' => 'BCP Cuenta Digital',
            ]),
    );
    $pdf = SyntheticPdf::fromText((string) file_get_contents(
        base_path('tests/Fixtures/Statements/bcp.txt'),
    ));

    $page = visit($this->browserApplicationUrl.'/login');
    $page
        ->type('#email', $owner->email)
        ->type('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/')
        ->navigate($this->browserApplicationUrl.'/statement-imports/create');
    selectPdfInBrowser($page, '#preview-statement', $pdf);
    $page
        ->press('Upload and check')
        ->assertSee('BCP')
        ->assertDontSee('Category for Savings movements')
        ->assertSee('2 unresolved');

    expect($page->value('#movement-0-classification'))
        ->toBe('savings')
        ->and($page->script("document.querySelector('#movement-0-classification').selectedOptions[0].textContent"))
        ->toBe('Savings')
        ->and($page->script("document.querySelector('[data-test=statement-movements]').textContent.includes('Suggested exclusion')"))
        ->toBeFalse()
        ->and($page->script("document.querySelector('[data-test=statement-movement-status-0] [data-status=needs_transaction]') !== null"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movement-status-0] [data-status=needs_classification]') === null"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('#movement-0-resolution').closest('td').cellIndex !== document.querySelector('[data-test=statement-movement-status-0]').closest('td').cellIndex"))
        ->toBeTrue();

    $page
        ->hover('[data-test=statement-movement-status-0] [data-status=needs_transaction]')
        ->assertSee('Choose a recorded Transaction or add this movement as a new one.');

    $page->select('#movement-0-resolution', 'create');

    $page->select(
        'select[aria-label="Classification for DEPOSITO"]',
        'transfer',
    );
    $page
        ->assertSee('0 unresolved')
        ->assertButtonEnabled('Confirm import')
        ->press('Confirm import')
        ->wait(1);
    expect($page->script("document.querySelector('[data-slot=alert]')?.textContent.trim() ?? ''"))->toBe('');
    expect($page->script('window.location.pathname'))->not->toBe('/statement-imports/create');
    $page
        ->assertPathBeginsWith('/statement-imports/')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(StatementMovement::query()->where('classification', 'savings')->count())->toBe(2)
        ->and(Transaction::query()->where('kind', 'transfer')->where('transfer_purpose', 'savings')->whereHas('statementMovement')->count())->toBe(2)
        ->and(Transaction::query()->whereNotNull('category_id')->doesntExist())->toBeTrue();
})->depends('the owner selects a statement once resolves exceptions and confirms the import');

test('an abandoned preview remains transient and is editable on a mobile viewport', function () {
    $owner = User::factory()->create();
    $pdf = SyntheticPdf::fromText((string) file_get_contents(
        base_path('tests/Fixtures/Statements/interbank.txt'),
    ));

    $page = visit($this->browserApplicationUrl.'/login');
    $page
        ->type('#email', $owner->email)
        ->type('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/')
        ->navigate($this->browserApplicationUrl.'/statement-imports/create')
        ->resize(390, 844);

    selectPdfInBrowser($page, '#preview-statement', $pdf);
    $page
        ->press('Upload and check')
        ->assertSee('Reconciled')
        ->assertSee('1 unresolved')
        ->assertVisible(
            'select[aria-label="Classification for Mercado Pago"]',
        )
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= document.documentElement.clientWidth'))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=statement-movements] [data-slot=table-container]').scrollWidth > document.querySelector('[data-test=statement-movements] [data-slot=table-container]').clientWidth"))
        ->toBeTrue()
        ->and(StatementImport::query()->doesntExist())->toBeTrue();

    $page
        ->refresh()
        ->assertDontSee('Reconciled');

    expect(StatementImport::query()->doesntExist())->toBeTrue();

    selectPdfInBrowser($page, '#preview-statement', $pdf);
    $page
        ->press('Upload and check')
        ->assertSee('Reconciled')
        ->click('Statement Imports')
        ->assertPathIs('/statement-imports')
        ->back()
        ->assertPathIs('/statement-imports/create')
        ->assertDontSee('Reconciled');

    expect(StatementImport::query()->doesntExist())->toBeTrue();

    selectPdfInBrowser($page, '#preview-statement', $pdf);
    $page
        ->press('Upload and check')
        ->assertSee('Reconciled')
        ->resize(1280, 720)
        ->click('[data-test="sidebar-menu-button"]')
        ->click('[data-test="logout-button"]')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(StatementImport::query()->doesntExist())->toBeTrue();
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
    $server = Process::path(public_path())
        ->env([
            'APP_ENV' => 'testing',
            'APP_URL' => $applicationUrl,
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'PHP_CLI_SERVER_WORKERS' => false,
            'SESSION_DRIVER' => 'database',
        ])
        ->timeout(120)
        ->start([
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$port}",
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
        ]);

    $server->waitUntil(
        fn (string $type, string $output): bool => str_contains($output, 'Development Server'),
    );

    return [$server, $applicationUrl];
}

function selectPdfInBrowser(mixed $page, string $selector, string $pdf): void
{
    $encodedSelector = json_encode($selector, JSON_THROW_ON_ERROR);
    $encodedPdf = json_encode(base64_encode($pdf), JSON_THROW_ON_ERROR);

    $page->script(<<<JAVASCRIPT
        (() => {
            const input = document.querySelector({$encodedSelector});
            const binary = atob({$encodedPdf});
            const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();

            transfer.items.add(new File([bytes], 'statement.pdf', {
                type: 'application/pdf',
            }));

            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'files')
                .set.call(input, transfer.files);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })()
        JAVASCRIPT);
}
