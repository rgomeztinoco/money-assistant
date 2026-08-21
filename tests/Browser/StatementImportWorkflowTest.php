<?php

use App\Models\StatementImport;
use App\Models\User;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\SyntheticPdf;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner discovers Statement Imports selects a PDF and revisits a confirmed import', function () {
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
        $page
            ->press('Preview statement')
            ->assertSee('INTERBANK')
            ->assertSee('Reconciled')
            ->assertSee('Minimum payment');
        expect($page->value('select[aria-label="Classification for Mercado Pago"]'))
            ->toBe('needs_classification');
        selectPdfInBrowser($page, '#confirm-statement', $pdf);
        $page
            ->press('Confirm Statement Import')
            ->assertSee('Classify every real movement before confirming the import.');

        expect(StatementImport::query()->doesntExist())->toBeTrue();

        $page->select(
            'select[aria-label="Classification for Mercado Pago"]',
            'already_recorded',
        );
        selectPdfInBrowser($page, '#confirm-statement', $pdf);
        $page
            ->press('Confirm Statement Import')
            ->assertPathBeginsWith('/statement-imports/')
            ->assertSee('Statement Movements')
            ->assertSee('Source reconciliation')
            ->assertSee('payment total usd')
            ->assertSee('Mercado Pago')
            ->assertSee('Already recorded')
            ->click('Statement Imports')
            ->assertPathIs('/statement-imports')
            ->assertSee('Interbank American Express')
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();

        expect(StatementImport::query()->count())->toBe(1);
    } finally {
        $server->stop();
    }
});

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
