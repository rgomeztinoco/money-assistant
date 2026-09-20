<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{directory: string, log: string} */
function createBoostComposerFixture(): array
{
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-boost-'.str()->uuid();
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    mkdir($temporaryDirectory, 0700, true);
    symlink(base_path('vendor'), $temporaryDirectory.'/vendor');
    file_put_contents($temporaryDirectory.'/composer.json', json_encode([
        'name' => 'money-assistant/boost-script-test',
        'scripts' => [
            'boost:update' => $composer['scripts']['boost:update'],
            'post-install-cmd' => $composer['scripts']['post-install-cmd'],
            'post-update-cmd' => $composer['scripts']['post-update-cmd'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($temporaryDirectory.'/artisan', <<<'PHP'
<?php

$command = implode(' ', array_slice($argv, 1));
file_put_contents(getenv('BOOST_TEST_LOG'), $command.PHP_EOL, FILE_APPEND);

if (($argv[1] ?? null) === 'boost:update') {
    echo "Boost fixture updated.\n";

    exit((int) getenv('BOOST_TEST_EXIT_CODE'));
}

exit(0);
PHP);

    return [
        'directory' => $temporaryDirectory,
        'log' => $temporaryDirectory.'/commands.log',
    ];
}

function deleteBoostComposerFixture(string $temporaryDirectory): void
{
    unlink($temporaryDirectory.'/vendor');
    (new Filesystem)->deleteDirectory($temporaryDirectory);
}

function runBoostComposerLifecycle(string $temporaryDirectory, string $script, bool $devMode, int $boostExitCode = 0): Process
{
    $process = new Process([
        getenv('COMPOSER_BINARY') ?: 'composer',
        'run-script',
        $devMode ? '--dev' : '--no-dev',
        '--no-interaction',
        $script,
    ], $temporaryDirectory, [
        'BOOST_TEST_EXIT_CODE' => (string) $boostExitCode,
        'BOOST_TEST_LOG' => $temporaryDirectory.'/commands.log',
    ]);
    $process->run();

    return $process;
}

/** @return list<string> */
function boostComposerCommandLog(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    return array_values(array_filter(explode(PHP_EOL, trim((string) file_get_contents($path)))));
}

test('Composer refreshes Boost resources after development installs and updates', function (string $script, array $expectedCommands): void {
    $fixture = createBoostComposerFixture();

    try {
        $process = runBoostComposerLifecycle($fixture['directory'], $script, devMode: true);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(boostComposerCommandLog($fixture['log']))->toBe($expectedCommands)
            ->and($process->getOutput())->toContain('Boost fixture updated.');
    } finally {
        deleteBoostComposerFixture($fixture['directory']);
    }
})->with([
    'install' => ['post-install-cmd', ['boost:update --ansi --no-discover']],
    'update' => ['post-update-cmd', [
        'vendor:publish --tag=laravel-assets --ansi --force',
        'boost:update --ansi --no-discover',
    ]],
]);

test('Composer skips Boost resources without development dependencies', function (string $script, array $expectedCommands): void {
    $fixture = createBoostComposerFixture();

    try {
        $process = runBoostComposerLifecycle($fixture['directory'], $script, devMode: false);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(boostComposerCommandLog($fixture['log']))->toBe($expectedCommands)
            ->and($process->getOutput())->not->toContain('Boost fixture updated.');
    } finally {
        deleteBoostComposerFixture($fixture['directory']);
    }
})->with([
    'install' => ['post-install-cmd', []],
    'update' => ['post-update-cmd', ['vendor:publish --tag=laravel-assets --ansi --force']],
]);

test('Composer fails when the Boost resource refresh fails', function (): void {
    $fixture = createBoostComposerFixture();

    try {
        $process = runBoostComposerLifecycle($fixture['directory'], 'post-install-cmd', devMode: true, boostExitCode: 23);

        expect($process->isSuccessful())->toBeFalse()
            ->and(boostComposerCommandLog($fixture['log']))->toBe(['boost:update --ansi --no-discover'])
            ->and($process->getErrorOutput())->toContain('Exit Code: 23');
    } finally {
        deleteBoostComposerFixture($fixture['directory']);
    }
});
