<?php

namespace Tests\Support;

final class ConfigureOpenClaw
{
    public static function asConfigured(): void
    {
        config([
            'services.openclaw.launcher_url' => 'https://t.me/money_assistant',
            'services.openclaw.capability.key_id' => 'openclaw-service',
            'services.openclaw.capability.public_key' => 'public-key',
            'services.openclaw.capability.agent_id' => 'money-assistant',
            'services.openclaw.capability.account_id' => 'owner-account',
            'services.openclaw.capability.conversation_id' => 'owner-conversation',
            'services.openclaw.capability.owner_sender_id' => 'owner-sender',
            'services.openclaw.hook.url' => 'http://127.0.0.1:19789/hooks/money-assistant',
            'services.openclaw.hook.token' => 'hook-token',
        ]);
    }
}
