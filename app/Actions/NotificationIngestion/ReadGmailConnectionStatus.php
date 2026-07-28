<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Models\GmailConnection;
use App\Models\User;

final class ReadGmailConnectionStatus
{
    /**
     * @return array{
     *     configured: bool,
     *     state: 'disconnected'|'connected'|'check_failed'|'reauthorization_required',
     *     account_identity: string|null,
     *     scope: string,
     *     connected_at: string|null,
     *     last_successful_check_at: string|null,
     *     last_check_failed_at: string|null,
     *     reauthorization_required_at: string|null
     * }
     */
    public function handle(User $owner): array
    {
        $connection = GmailConnection::query()->whereBelongsTo($owner, 'owner')->first();

        return [
            'configured' => filled(config('services.gmail.client_id'))
                && filled(config('services.gmail.client_secret'))
                && filled(config('services.gmail.redirect_uri'))
                && config('services.gmail.oauth_publishing_status') === 'production',
            'state' => match (true) {
                $connection === null => 'disconnected',
                $connection->ingestionIsPaused() => 'reauthorization_required',
                $connection->last_check_failed_at !== null => 'check_failed',
                default => 'connected',
            },
            'account_identity' => $connection?->gmail_account_identity,
            'scope' => Gmail::READ_ONLY_SCOPE,
            'connected_at' => $connection?->connected_at?->toIso8601String(),
            'last_successful_check_at' => $connection?->last_successful_check_at?->toIso8601String(),
            'last_check_failed_at' => $connection?->last_check_failed_at?->toIso8601String(),
            'reauthorization_required_at' => $connection?->reauthorization_required_at?->toIso8601String(),
        ];
    }
}
