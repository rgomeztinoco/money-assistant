<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->developmentCompose = Yaml::parseFile(base_path('compose.yaml'));
    $this->productionCompose = Yaml::parseFile(base_path('compose.production.yaml'));
});

test('development services publish ports only on loopback', function (): void {
    $services = $this->developmentCompose['services'];

    expect($services['laravel.test']['ports'])->toBe([
        '127.0.0.1:${APP_PORT:-8080}:80',
        '127.0.0.1:${VITE_PORT:-5173}:${VITE_PORT:-5173}',
    ])->and($services['pgsql']['ports'])->toBe([
        '127.0.0.1:${FORWARD_DB_PORT:-5433}:5432',
    ])->and($services['mailpit']['ports'])->toBe([
        '127.0.0.1:${FORWARD_MAILPIT_PORT:-1025}:1025',
        '127.0.0.1:${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025',
    ]);
});

test('starting Sail launches only manually controlled development services', function (): void {
    $services = $this->developmentCompose['services'];

    expect(array_keys($services))->toBe(['laravel.test', 'pgsql', 'mailpit']);

    foreach ($services as $service) {
        expect($service)->not->toHaveKey('restart');
    }
});

test('development background processes have explicit interactive lifecycles', function (): void {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['dev'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'npx concurrently -c "#93c5fd,#fb7185,#fdba74" "php artisan queue:work" "php artisan pail --timeout=0" "npm run dev" --names=queue,logs,vite --kill-others',
    ])->and($composer['scripts']['dev:scheduler'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'php artisan schedule:work --no-interaction',
    ]);
});

test('development and production lifecycle commands target isolated Compose resources', function (): void {
    $developmentProject = $this->developmentCompose['name'];
    $productionProject = $this->productionCompose['name'];
    $projectResourceNames = fn (array $compose, string $resourceType): array => array_map(
        fn (string $resource): string => $compose['name'].'_'.$resource,
        array_keys($compose[$resourceType]),
    );
    $developmentNetworks = $projectResourceNames($this->developmentCompose, 'networks');
    $productionNetworks = $projectResourceNames($this->productionCompose, 'networks');
    $developmentVolumes = $projectResourceNames($this->developmentCompose, 'volumes');
    $productionVolumes = $projectResourceNames($this->productionCompose, 'volumes');
    $productionService = file_get_contents(base_path('money-assistant-production.service'));
    $credentialRehearsal = file_get_contents(base_path('rehearse-credential-rotation'));

    expect($developmentProject)->toBe('money-assistant-development')
        ->and($productionProject)->toBe('money-assistant-production')
        ->and(array_intersect($developmentNetworks, $productionNetworks))->toBe([])
        ->and(array_intersect($developmentVolumes, $productionVolumes))->toBe([])
        ->and($this->developmentCompose['services'])->not->toHaveKey('migrate')
        ->and($this->productionCompose['services'])->toHaveKey('migrate')
        ->and(substr_count($productionService, '--project-name money-assistant-production'))->toBe(2)
        ->and($productionService)->toContain('--env-file /etc/money-assistant/production.env')
        ->and($credentialRehearsal)
        ->toContain('project_name="${COMPOSE_PROJECT_NAME:-money-assistant-production}"')
        ->toContain('--project-name "$project_name"')
        ->toContain('--env-file "$environment_file"');
});

test('development integrations use separate configuration without widening Gmail access', function (): void {
    $developmentEnvironment = file_get_contents(base_path('.env.example'));
    $productionEnvironment = file_get_contents(base_path('.env.production.example'));
    $developmentOpenClawEnvironment = file_get_contents(base_path('openclaw/development.env.example'));
    $productionOpenClawEnvironment = file_get_contents(base_path('openclaw/production.env.example'));
    $developmentCompose = file_get_contents(base_path('compose.yaml'));
    $developmentOpenClawLauncher = file_get_contents(base_path('openclaw/run-development'));
    $gmailContract = file_get_contents(base_path('app/Contracts/Gmail.php'));
    $openClawPolicy = file_get_contents(base_path('openclaw/money-assistant-agent-policy.json'));
    $pluginManifest = json_decode(
        file_get_contents(base_path('openclaw/money-assistant-plugin/openclaw.plugin.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($developmentEnvironment)
        ->toContain('APP_URL=http://localhost:8080')
        ->toContain('APP_PORT=8080')
        ->toContain('FORWARD_DB_PORT=5433')
        ->toContain('GOOGLE_GMAIL_CLIENT_ID=')
        ->toContain('GOOGLE_GMAIL_CLIENT_SECRET=')
        ->toContain('GOOGLE_GMAIL_OAUTH_PUBLISHING_STATUS=testing')
        ->toContain('OPENCLAW_CAPABILITY_ACCOUNT_ID=money-assistant-development')
        ->toContain('OPENCLAW_LAUNCHER_URL=https://t.me/money_assistant_development_bot')
        ->toContain('OPENCLAW_HOOK_URL=http://127.0.0.1:29789/hooks/money-assistant')
        ->and($productionEnvironment)
        ->toContain('GOOGLE_GMAIL_REDIRECT_URI=https://money-assistant.example.ts.net:8443/settings/connections/gmail/callback')
        ->toContain('GOOGLE_GMAIL_OAUTH_PUBLISHING_STATUS=production')
        ->toContain('OPENCLAW_CAPABILITY_ACCOUNT_ID=money-assistant-owner')
        ->toContain('OPENCLAW_LAUNCHER_URL=https://t.me/money_assistant_bot')
        ->toContain('OPENCLAW_HOOK_URL=http://127.0.0.1:19789/hooks/money-assistant')
        ->and($developmentOpenClawEnvironment)
        ->toContain('OPENCLAW_PROFILE=money-assistant-development')
        ->toContain('OPENCLAW_STATE_DIR=${HOME}/.openclaw-money-assistant-development')
        ->toContain('OPENCLAW_GATEWAY_PORT=29789')
        ->toContain('TELEGRAM_BOT_TOKEN=')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_ACCOUNT_ID=money-assistant-development')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_CAPABILITY_ORIGIN=http://127.0.0.1:8080')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_PRIVATE_KEY_FILE=${HOME}/.config/money-assistant-development/openclaw_capability_private_key')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_PLUGIN_PATH=')
        ->and($productionOpenClawEnvironment)
        ->toContain('OPENCLAW_PROFILE=money-assistant-production')
        ->toContain('OPENCLAW_STATE_DIR=/home/openclaw/.openclaw-money-assistant-production')
        ->toContain('OPENCLAW_CONFIG_PATH=/opt/money-assistant/openclaw/money-assistant-agent-policy.json')
        ->toContain('OPENCLAW_GATEWAY_PORT=19789')
        ->toContain('TELEGRAM_BOT_TOKEN=')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_ACCOUNT_ID=money-assistant-owner')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_CAPABILITY_ORIGIN=http://127.0.0.1:8443')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_PRIVATE_KEY_FILE=/etc/money-assistant/secrets/openclaw_capability_private_key')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_PLUGIN_PATH=/opt/money-assistant/openclaw/money-assistant-plugin')
        ->and($developmentOpenClawLauncher)
        ->toContain('environment_file="${OPENCLAW_DEVELOPMENT_ENV_FILE:-${script_directory}/development.env}"')
        ->toContain('OPENCLAW_MONEY_ASSISTANT_PLUGIN_PATH="${OPENCLAW_MONEY_ASSISTANT_PLUGIN_PATH:-${script_directory}/money-assistant-plugin}"')
        ->toContain('exec openclaw gateway --port "$OPENCLAW_GATEWAY_PORT"')
        ->toContain('[ -n "${TELEGRAM_BOT_TOKEN:-}" ] || fail \'a separate development Telegram bot token is required\'')
        ->not->toContain('systemctl')
        ->and($gmailContract)
        ->toContain("public const READ_ONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';")
        ->and($openClawPolicy)
        ->toContain('"capabilityOrigin": "${OPENCLAW_MONEY_ASSISTANT_CAPABILITY_ORIGIN}"')
        ->toContain('"${OPENCLAW_MONEY_ASSISTANT_PLUGIN_PATH}"')
        ->and($pluginManifest['configSchema']['required'])->toContain('capabilityOrigin')
        ->and($pluginManifest['configSchema']['properties'])->toHaveKey('capabilityOrigin')
        ->and($developmentCompose)
        ->not->toContain('tailscale', 'https://', '8443');
});

test('continuous integration proves live development and production command isolation', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)
        ->toContain('Rehearse development and production coexistence')
        ->toContain('COMPOSE_PROJECT_NAME=money-assistant-development vendor/bin/sail up -d --wait')
        ->toContain('COMPOSE_PROJECT_NAME=money-assistant-development vendor/bin/sail artisan migrate --force')
        ->toContain('COMPOSE_PROJECT_NAME=money-assistant-development vendor/bin/sail stop')
        ->toContain('COMPOSE_PROJECT_NAME=money-assistant-development vendor/bin/sail down --volumes --remove-orphans')
        ->toContain('production_migrations_before')
        ->toContain('production_container_ids_before');
});
