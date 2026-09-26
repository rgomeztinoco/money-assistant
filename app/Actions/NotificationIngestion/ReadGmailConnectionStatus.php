<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\GmailSynchronizationType;
use App\Integrations\Gmail\PreviewGmail;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Cron\CronExpression;

final class ReadGmailConnectionStatus
{
    private const int SYNCHRONIZATION_MAXIMUM_AGE_IN_MINUTES = 5;

    /**
     * @return array{
     *     configured: bool,
     *     preview: bool,
     *     state: 'disconnected'|'connected'|'stale'|'check_failed'|'reauthorization_required',
     *     account_identity: string|null,
     *     scope: string,
     *     connected_at: string|null,
     *     last_successful_check_at: string|null,
     *     last_successful_sync_at: string|null,
     *     next_scheduled_sync_at: string|null,
     *     retryable_unsupported_count: int,
     *     latest_failure: array{type: 'synchronization'|'message', occurred_at: string, error_code: string, discovery_id: int|null, message_id: string|null, retryable: bool}|null,
     *     last_check_failed_at: string|null,
     *     reauthorization_required_at: string|null
     * }
     */
    public function handle(User $owner): array
    {
        $preview = app()->environment('local') && (bool) config('services.gmail.preview_enabled');
        $connection = GmailConnection::query()->whereBelongsTo($owner, 'owner')->first();
        $latestSynchronizationAt = $connection === null
            ? null
            : ($connection->last_successful_sync_at ?? $connection->connected_at);
        $synchronizationIsStale = $latestSynchronizationAt?->isBefore(
            now()->subMinutes(self::SYNCHRONIZATION_MAXIMUM_AGE_IN_MINUTES),
        ) ?? false;
        $failedMessage = $connection?->messageDiscoveries()
            ->whereNotNull('processing_failed_at')
            ->latest('processing_failed_at')
            ->first();
        $latestFailure = $this->latestFailure($connection, $failedMessage);

        return [
            'configured' => $preview || (filled(config('services.gmail.client_id'))
                && filled(config('services.gmail.client_secret'))
                && filled(config('services.gmail.redirect_uri'))
                && config('services.gmail.oauth_publishing_status') === 'production'),
            'preview' => $preview && $connection?->gmail_account_identity === PreviewGmail::ACCOUNT_IDENTITY,
            'state' => match (true) {
                $connection === null => 'disconnected',
                $connection->ingestionIsPaused() => 'reauthorization_required',
                $connection->last_check_failed_at !== null => 'check_failed',
                $synchronizationIsStale => 'stale',
                default => 'connected',
            },
            'account_identity' => $connection?->gmail_account_identity,
            'scope' => Gmail::READ_ONLY_SCOPE,
            'connected_at' => $connection?->connected_at?->toIso8601String(),
            'last_successful_check_at' => $connection?->last_successful_check_at?->toIso8601String(),
            'last_successful_sync_at' => $connection?->last_successful_sync_at?->toIso8601String(),
            'next_scheduled_sync_at' => $connection !== null && ! $connection->ingestionIsPaused()
                ? $this->nextScheduledSynchronizationAt()
                : null,
            'retryable_unsupported_count' => $connection === null
                ? 0
                : SpendingNotificationReference::query()
                    ->whereRetryableUnsupportedFor($connection)
                    ->count(),
            'latest_failure' => $latestFailure,
            'last_check_failed_at' => $connection?->last_check_failed_at?->toIso8601String(),
            'reauthorization_required_at' => $connection?->reauthorization_required_at?->toIso8601String(),
        ];
    }

    private function nextScheduledSynchronizationAt(): string
    {
        $nextRunAt = (new CronExpression(GmailSynchronizationType::INCREMENTAL_SCHEDULE))->getNextRunDate(
            now(),
        );

        return CarbonImmutable::instance($nextRunAt)->toIso8601String();
    }

    /**
     * @return array{
     *     type: 'synchronization'|'message',
     *     occurred_at: string,
     *     error_code: string,
     *     discovery_id: int|null,
     *     message_id: string|null,
     *     retryable: bool
     * }|null
     */
    private function latestFailure(
        ?GmailConnection $connection,
        ?GmailMessageDiscovery $failedMessage,
    ): ?array {
        if ($connection === null) {
            return null;
        }

        if (
            $failedMessage?->processing_failed_at !== null
            && (
                $connection->last_synchronization_failed_at === null
                || $failedMessage->processing_failed_at->isAfter(
                    $connection->last_synchronization_failed_at,
                )
            )
        ) {
            return [
                'type' => 'message',
                'occurred_at' => $failedMessage->processing_failed_at->toIso8601String(),
                'error_code' => $failedMessage->last_error_code
                    ?? 'gmail_message_processing_failed',
                'discovery_id' => $failedMessage->id,
                'message_id' => $failedMessage->message_id,
                'retryable' => $failedMessage->failed_job_uuid !== null
                    && ! $connection->ingestionIsPaused(),
            ];
        }

        if ($connection->last_synchronization_failed_at === null) {
            return null;
        }

        return [
            'type' => 'synchronization',
            'occurred_at' => $connection->last_synchronization_failed_at->toIso8601String(),
            'error_code' => $connection->last_synchronization_error_code
                ?? 'gmail_synchronization_failed',
            'discovery_id' => null,
            'message_id' => null,
            'retryable' => false,
        ];
    }
}
