<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->productionCompose = Yaml::parseFile(base_path('compose.production.yaml'));
});

test('a clean host can run only immutable production services', function () {
    $services = $this->productionCompose['services'];

    expect($services)->toHaveKeys([
        'postgres',
        'migrate',
        'web',
        'worker',
        'scheduler',
        'proxy',
    ]);

    foreach ($services as $service) {
        expect($service)->not->toHaveKey('build');

        foreach ($service['volumes'] ?? [] as $volume) {
            expect($volume['type'])->not->toBe('bind');
        }
    }
});

test('web worker scheduler PostgreSQL and the private proxy publish health checks', function () {
    $services = $this->productionCompose['services'];

    foreach (['web', 'worker', 'scheduler', 'postgres', 'proxy'] as $service) {
        expect($services[$service]['healthcheck']['test'])
            ->toBeArray()
            ->not->toBeEmpty();
    }

    expect(implode(' ', $services['worker']['healthcheck']['test']))
        ->toContain('app:health-check worker')
        ->and(implode(' ', $services['scheduler']['healthcheck']['test']))
        ->toContain('app:health-check scheduler')
        ->and($services['proxy']['depends_on']['web']['condition'])
        ->toBe('service_healthy');
});

test('application roles share one pinned image and restart independently', function () {
    $services = $this->productionCompose['services'];
    $applicationImage = '${APP_IMAGE_REPOSITORY:?Set APP_IMAGE_REPOSITORY}@${APP_IMAGE_DIGEST:?Set APP_IMAGE_DIGEST}';

    foreach (['migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service]['image'])->toBe($applicationImage);
    }

    expect($services['web']['command'])->not->toBe($services['worker']['command'])
        ->and($services['worker']['command'])->not->toBe($services['scheduler']['command']);

    foreach (['web', 'worker', 'scheduler', 'postgres', 'proxy'] as $service) {
        expect($services[$service]['restart'])->toBe('unless-stopped');
    }
});

test('production permits the state-bound Gmail callback and mounts its OAuth secret only in the web role', function () {
    $services = $this->productionCompose['services'];
    $entrypoint = file_get_contents(base_path('docker-entrypoint.production'));

    expect($services['web']['environment']['SESSION_SAME_SITE'])->toBe('lax')
        ->and($services['web']['environment']['GOOGLE_GMAIL_OAUTH_PUBLISHING_STATUS'])->toBe('production')
        ->and($services['web']['environment']['GOOGLE_GMAIL_REDIRECT_URI'])
        ->toBe('https://${PRIVATE_HOSTNAME:?Set PRIVATE_HOSTNAME}/settings/connections/gmail/callback')
        ->and($services['web']['environment']['GOOGLE_GMAIL_CLIENT_SECRET_FILE'])
        ->toBe('/run/secrets/google_gmail_client_secret')
        ->and($services['web']['secrets'])->toContain('google_gmail_client_secret')
        ->and($services['worker']['secrets'])->not->toContain('google_gmail_client_secret')
        ->and($services['scheduler']['secrets'])->not->toContain('google_gmail_client_secret')
        ->and($entrypoint)->toContain('read_secret GOOGLE_GMAIL_CLIENT_SECRET');
});

test('only PostgreSQL and the pinned reverse proxy expose loopback ports', function () {
    $services = $this->productionCompose['services'];
    $environment = file_get_contents(base_path('.env.production.example'));
    preg_match('/^POSTGRES_IMAGE=(.+)$/m', $environment, $postgresImage);

    expect($services['postgres']['image'])
        ->toBe('${POSTGRES_IMAGE:?Set POSTGRES_IMAGE to a pinned PostgreSQL image}')
        ->and($postgresImage[1] ?? null)->toMatch('/@sha256:[a-f0-9]{64}$/')
        ->and($services['proxy']['image'])->toMatch('/@sha256:[a-f0-9]{64}$/')
        ->and($services['proxy']['ports'])->toBe([
            [
                'target' => 8080,
                'published' => 8443,
                'host_ip' => '127.0.0.1',
                'protocol' => 'tcp',
            ],
        ])
        ->and($services['postgres']['ports'])->toBe([
            [
                'target' => 5432,
                'published' => 5432,
                'host_ip' => '127.0.0.1',
                'protocol' => 'tcp',
            ],
        ]);

    foreach (['migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service])->not->toHaveKey('ports');
    }
});

test('Tailscale is the exclusive HTTPS ingress for approved owner devices', function () {
    $policy = file_get_contents(base_path('tailscale-policy.hujson'));
    $service = file_get_contents(base_path('money-assistant-tailnet.service'));

    expect($policy)
        ->toContain('"tag:money-assistant-approved-device"')
        ->toContain('"srcPosture": ["posture:approved-owner-device"]')
        ->toContain('"tag:money-assistant:443"')
        ->toContain('"deny": ["tag:money-assistant:80", "tag:money-assistant:8443", "tag:money-assistant:18789"]')
        ->and($service)
        ->toContain('tailscale wait')
        ->toContain('tailscale serve --bg --https=443 http://127.0.0.1:8443')
        ->not->toContain('--set-path=/hooks/money-assistant')
        ->not->toContain('tailscale funnel');
});

test('OpenClaw integrations have isolated transport and directional credentials', function () {
    $services = $this->productionCompose['services'];

    expect($services)->not->toHaveKey('openclaw')
        ->and($services['web']['secrets'])->toContain('openclaw_capability_public_key')
        ->and($services['worker']['secrets'])->toContain('openclaw_hook_token')
        ->and($services['web']['secrets'])->not->toContain('openclaw_hook_token')
        ->and($services['worker']['secrets'])->not->toContain('openclaw_capability_public_key')
        ->and($services['worker']['environment']['OPENCLAW_HOOK_URL'])
        ->toBe('${OPENCLAW_HOOK_URL:?Set OPENCLAW_HOOK_URL to the private mapped hook}');

    expect(file_get_contents(base_path('docker-entrypoint.production')))
        ->toContain('read_secret OPENCLAW_CAPABILITY_PUBLIC_KEY')
        ->toContain('read_secret OPENCLAW_HOOK_TOKEN');

    foreach ($services as $service) {
        expect(array_keys($service['environment'] ?? []))
            ->not->toContain('OPENCLAW_GATEWAY_TOKEN')
            ->not->toContain('OPENCLAW_MONEY_ASSISTANT_PRIVATE_KEY')
            ->not->toContain('OPENCLAW_CAPABILITY_PRIVATE_KEY');

        foreach ($service['volumes'] ?? [] as $volume) {
            expect($volume['source'] ?? null)->not->toBe('openclaw');
        }
    }
});

test('the dedicated Money Assistant agent exposes only its bounded financial plugin', function () {
    $pluginRoot = base_path('openclaw/money-assistant-plugin');
    $manifest = json_decode(
        file_get_contents($pluginRoot.'/openclaw.plugin.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $package = json_decode(
        file_get_contents($pluginRoot.'/package.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $policy = json_decode(
        file_get_contents(base_path('openclaw/money-assistant-agent-policy.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $agent = collect($policy['agents']['list'])->firstWhere('id', 'money-assistant');
    $receiptConfig = $policy['plugins']['entries']['money-assistant']['config'];

    expect($manifest['id'])->toBe('money-assistant')
        ->and($manifest['contracts'])->toBe([
            'tools' => [
                'money_assistant_transaction_read',
                'money_assistant_transaction_prepare',
                'money_assistant_transaction_confirm',
                'money_assistant_category_read',
                'money_assistant_category_prepare',
                'money_assistant_category_confirm',
                'money_assistant_receipt_proposal_submit',
                'money_assistant_receipt_breakdown_prepare',
                'money_assistant_receipt_breakdown_confirm',
                'money_assistant_reminder_read',
                'money_assistant_reminder_respond',
            ],
        ])
        ->and($manifest['configSchema']['additionalProperties'])->toBeFalse()
        ->and($package['peerDependencies']['openclaw'])->toBe('2026.7.1')
        ->and($package['openclaw']['build'])->toBe([
            'openclawVersion' => '2026.7.1',
            'pluginSdkVersion' => '2026.7.1',
        ])
        ->and($policy['plugins']['allow'])->toContain('money-assistant')
        ->and($policy['plugins']['entries']['money-assistant']['enabled'])->toBeTrue()
        ->and($policy['plugins']['entries']['money-assistant']['hooks'])->toBe([
            'allowConversationAccess' => true,
        ])
        ->and($agent['skills'])->toBe([])
        ->and($agent)->not->toHaveKey('model')
        ->and($agent['memorySearch'])->toBe(['enabled' => false])
        ->and($policy['agents']['defaults']['timeoutSeconds'])->toBe(1800)
        ->and($agent['heartbeat']['every'])->toBe('0m')
        ->and($agent['tools']['allow'])->toBe([
            'money_assistant_transaction_read',
            'money_assistant_transaction_prepare',
            'money_assistant_transaction_confirm',
            'money_assistant_category_read',
            'money_assistant_category_prepare',
            'money_assistant_category_confirm',
            'money_assistant_receipt_proposal_submit',
            'money_assistant_receipt_breakdown_prepare',
            'money_assistant_receipt_breakdown_confirm',
            'money_assistant_reminder_read',
            'money_assistant_reminder_respond',
        ])
        ->and($policy['bindings'])->toBe([
            [
                'agentId' => 'money-assistant',
                'match' => [
                    'channel' => 'telegram',
                    'accountId' => '${OPENCLAW_MONEY_ASSISTANT_ACCOUNT_ID}',
                    'peer' => [
                        'kind' => 'direct',
                        'id' => '${OPENCLAW_MONEY_ASSISTANT_OWNER_SENDER_ID}',
                    ],
                ],
            ],
        ])
        ->and($policy['hooks'])->toBe([
            'enabled' => true,
            'token' => '${OPENCLAW_MONEY_ASSISTANT_HOOK_TOKEN}',
            'path' => '/hooks',
            'allowedAgentIds' => ['money-assistant'],
            'allowRequestSessionKey' => false,
            'mappings' => [[
                'id' => 'money-assistant-reminders',
                'match' => ['path' => 'money-assistant'],
                'action' => 'agent',
                'agentId' => 'money-assistant',
                'wakeMode' => 'now',
                'name' => 'Money Assistant Reminder',
                'sessionKey' => 'hook:money-assistant:reminders',
                'messageTemplate' => 'Fetch Reminder event {{event_id}} that occurred at {{occurred_at}} with money_assistant_reminder_read. If channel_delivered_at is already set, return NO_REPLY. Otherwise deliver one concise owner-visible digest. Do not treat delivery as acknowledgement or resolution.',
                'deliver' => true,
                'channel' => 'telegram',
                'to' => '${OPENCLAW_MONEY_ASSISTANT_OWNER_SENDER_ID}',
                'timeoutSeconds' => 300,
            ]],
        ])
        ->and($policy['channels']['telegram']['defaultAccount'])
        ->toBe('${OPENCLAW_MONEY_ASSISTANT_ACCOUNT_ID}')
        ->and($policy['channels']['telegram']['actions'])->toBe([
            'sendMessage' => true,
            'deleteMessage' => true,
        ])
        ->and($policy['auth']['profiles'])->toBe([
            'openai:money-assistant-oauth' => [
                'provider' => 'openai',
                'mode' => 'oauth',
                'displayName' => 'Existing Money Assistant OpenAI OAuth account',
            ],
        ])
        ->and($policy['auth']['order']['openai'])->toBe([
            'openai:money-assistant-oauth',
        ])
        ->and($policy['agents']['defaults']['imageModel'])->toBe([
            'primary' => 'openai/gpt-5.6',
            'fallbacks' => [],
        ])
        ->and($policy['agents']['defaults']['model'])->toBe([
            'primary' => 'openai/gpt-5.6',
            'fallbacks' => [],
        ])
        ->and(array_keys($policy['agents']['defaults']['models']))->toBe(['openai/gpt-5.6'])
        ->and($policy['commands'])->toBe([
            'native' => false,
            'nativeSkills' => false,
            'text' => false,
            'bash' => false,
            'config' => false,
            'mcp' => false,
            'plugins' => false,
            'debug' => false,
            'restart' => false,
        ])
        ->and($policy['agents']['defaults']['mediaGenerationAutoProviderFallback'])->toBeFalse()
        ->and($policy['logging']['redactSensitive'])->toBe('tools')
        ->and($policy['logging']['level'])->toBe('warn')
        ->and($policy['logging']['consoleLevel'])->toBe('warn')
        ->and($receiptConfig['receiptMediaRoot'])
        ->toBe('${OPENCLAW_MONEY_ASSISTANT_RECEIPT_MEDIA_ROOT}')
        ->and($receiptConfig['receiptProcessingEnabled'])->toBeFalse()
        ->and($receiptConfig['receiptDisclosureDelivered'])->toBeFalse()
        ->and($receiptConfig['receiptDisclosureAccepted'])->toBeFalse()
        ->and($receiptConfig['openAiModelImprovementDisabled'])->toBeFalse()
        ->and($receiptConfig['codexFullEnvironmentTrainingDisabled'])->toBeFalse()
        ->and($receiptConfig['openAiOAuthProfileId'])->toBe('openai:money-assistant-oauth')
        ->and($receiptConfig['openAiOAuthCredentialVersion'])
        ->toBe('${OPENCLAW_MONEY_ASSISTANT_OPENAI_OAUTH_CREDENTIAL_VERSION}')
        ->and($receiptConfig['receiptPolicyVersion'])->toBe('openai-oauth-gpt-5.6-v1')
        ->and($receiptConfig['receiptConfirmedPolicyVersion'])->toBe('')
        ->and($receiptConfig['receiptConfirmedOAuthProfileId'])->toBe('')
        ->and($receiptConfig['receiptConfirmedOAuthCredentialVersion'])->toBe('');

    expect(file_get_contents($pluginRoot.'/src/index.ts'))
        ->toContain("const CAPABILITY_PATH = '/api/openclaw/v1/transport'")
        ->toContain('toolContext.senderIsOwner !== true')
        ->toContain('toolContext.requesterSenderId')
        ->toContain('toolContext.sessionId')
        ->toContain("api.on('message_received'")
        ->toContain('message_id: admission.messageId')
        ->toContain('message_id: admission.interactionId')
        ->toContain('admission.occurredAtSeconds')
        ->toContain("'transaction.manual.prepare'")
        ->toContain("'transaction.manual.confirm'")
        ->toContain("'category.mutation.prepare'")
        ->toContain("'category.mutation.confirm'")
        ->toContain("'receipt.proposal.submit'")
        ->toContain("'reminder.read'")
        ->toContain("'reminder.respond'")
        ->toContain("'reminder.delivery.record'")
        ->toContain('idempotency_key')
        ->toContain("createHash('sha256').update(body).digest('hex')")
        ->not->toContain('${toolContext.sessionId}:${toolCallId}')
        ->not->toContain('OPENCLAW_GATEWAY_TOKEN');
});

test('OpenClaw receipt media has an independent one-hour crash cleanup ceiling', function () {
    $cleanup = file_get_contents(base_path('openclaw/cleanup-receipt-media'));
    $service = file_get_contents(base_path('openclaw/money-assistant-receipt-cleanup.service'));
    $timer = file_get_contents(base_path('openclaw/money-assistant-receipt-cleanup.timer'));
    $installer = file_get_contents(base_path('openclaw/install-receipt-cleanup'));

    expect($cleanup)
        ->toContain('OPENCLAW_MONEY_ASSISTANT_RECEIPT_MEDIA_ROOT')
        ->toContain('-mmin +54')
        ->toContain('-delete')
        ->and($service)
        ->toContain('EnvironmentFile=/etc/money-assistant/openclaw-receipt-cleanup.env')
        ->toContain('ExecStart=/opt/money-assistant/openclaw/cleanup-receipt-media')
        ->toContain('ProtectSystem=full')
        ->not->toContain('ReadWritePaths=${')
        ->and($timer)
        ->toContain('OnBootSec=5m')
        ->toContain('OnUnitActiveSec=5m')
        ->toContain('Persistent=true')
        ->and($installer)
        ->toContain('openclaw-receipt-cleanup.env')
        ->toContain('money-assistant-receipt-cleanup.service')
        ->toContain('money-assistant-receipt-cleanup.timer')
        ->toContain('systemctl enable --now money-assistant-receipt-cleanup.timer');
});

test('the production stack ships a private ingress verifier', function () {
    $verifier = file_get_contents(base_path('verify-private-ingress'));

    expect($verifier)
        ->toContain('tailscale serve status --json')
        ->toContain('tailscale funnel status --json')
        ->toContain('ufw status verbose')
        ->toContain('ss -H -lnt')
        ->toContain('docker compose');
});

test('deployments record pinned application and database versions without secrets', function () {
    $services = $this->productionCompose['services'];
    $deployment = file_get_contents(base_path('deploy-production'));

    expect($services['postgres']['image'])
        ->toBe('${POSTGRES_IMAGE:?Set POSTGRES_IMAGE to a pinned PostgreSQL image}')
        ->and($deployment)
        ->toContain('flock')
        ->toContain('application_image')
        ->toContain('database_image')
        ->toContain('application_revision')
        ->toContain('database_version')
        ->toContain('previous_application_image')
        ->not->toContain('docker compose config')
        ->not->toContain('set -x');
});

test('systemd restores the production stack before private ingress after reboot', function () {
    $productionService = file_get_contents(base_path('money-assistant-production.service'));
    $tailnetService = file_get_contents(base_path('money-assistant-tailnet.service'));
    $installer = file_get_contents(base_path('install-production-services'));
    $rehearsal = file_get_contents(base_path('rehearse-production-reboot'));

    expect($productionService)
        ->toContain('Requires=docker.service')
        ->toContain('After=docker.service')
        ->toContain('up --detach --wait --pull never')
        ->toContain('WantedBy=multi-user.target')
        ->and($tailnetService)
        ->toContain('Requires=money-assistant-production.service')
        ->toContain('After=money-assistant-production.service tailscale-online.target')
        ->and($installer)
        ->toContain('systemctl enable --now money-assistant-production.service money-assistant-tailnet.service')
        ->and($rehearsal)
        ->toContain('deployment.lock')
        ->toContain('systemctl reboot')
        ->toContain('systemctl is-active --quiet')
        ->toContain('verify-private-ingress')
        ->toContain('app:deployment-rehearsal:verify');
});

test('failed deployments restore and verify the previous healthy release', function () {
    $deployment = file_get_contents(base_path('deploy-production'));

    expect($deployment)
        ->toContain('rollback')
        ->toContain('previous.env')
        ->toContain('up --detach --wait --pull never')
        ->toContain('verify_stack');
});
