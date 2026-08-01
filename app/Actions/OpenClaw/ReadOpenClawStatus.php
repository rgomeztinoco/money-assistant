<?php

namespace App\Actions\OpenClaw;

final class ReadOpenClawStatus
{
    /** @return array{state: 'configured'|'unavailable', launcher_url: string|null} */
    public function handle(): array
    {
        $launcherUrl = config('services.openclaw.launcher_url');
        $isConfigured = collect([
            $launcherUrl,
            config('services.openclaw.capability.key_id'),
            config('services.openclaw.capability.public_key'),
            config('services.openclaw.capability.agent_id'),
            config('services.openclaw.capability.account_id'),
            config('services.openclaw.capability.conversation_id'),
            config('services.openclaw.capability.owner_sender_id'),
            config('services.openclaw.hook.url'),
            config('services.openclaw.hook.token'),
        ])->every(fn (mixed $value): bool => filled($value));

        return [
            'state' => $isConfigured ? 'configured' : 'unavailable',
            'launcher_url' => is_string($launcherUrl) && filled($launcherUrl)
                ? $launcherUrl
                : null,
        ];
    }
}
