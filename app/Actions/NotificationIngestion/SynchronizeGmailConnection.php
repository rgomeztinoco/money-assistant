<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Exceptions\GmailResponseInvalid;
use App\GmailSynchronizationType;
use App\Integrations\Gmail\GmailHistoryExpired;
use App\Jobs\ProcessGmailMessage;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use Illuminate\Support\Facades\DB;

final class SynchronizeGmailConnection
{
    private const INITIAL_SCAN_OVERLAP_MINUTES = 5;

    private const RECONCILIATION_DAYS = 7;

    public function __construct(
        private Gmail $gmail,
        private RefreshGmailConnection $refreshGmailConnection,
    ) {}

    public function handle(
        int $connectionId,
        GmailSynchronizationType $type,
    ): void {
        $connection = GmailConnection::query()->findOrFail($connectionId);

        if ($connection->ingestionIsPaused()) {
            return;
        }

        if ($connection->access_token_expires_at->lessThanOrEqualTo(now()->addMinute())) {
            $connection = $this->refreshGmailConnection->handle($connection);
        }

        if ($connection->history_id === null) {
            $connection = $this->initializeHistoryCursor($connection);

            if ($connection->history_id === null) {
                return;
            }
        }

        if ($connection->initial_sync_completed_at === null) {
            $this->synchronizeInitialWindow($connection);

            return;
        }

        if ($type === GmailSynchronizationType::Reconciliation) {
            $this->reconcileRecentMessages($connection);

            return;
        }

        $this->synchronizeIncrementalHistory($connection);
    }

    private function initializeHistoryCursor(GmailConnection $connection): GmailConnection
    {
        $profile = $this->gmail->profile($connection->access_token);

        if ($profile->accountIdentity !== $connection->gmail_account_identity) {
            throw GmailResponseInvalid::forOperation('profile identity');
        }

        return DB::transaction(function () use ($connection, $profile): GmailConnection {
            $current = GmailConnection::query()
                ->lockForUpdate()
                ->findOrFail($connection->id);

            if ($current->ingestionIsPaused()
                || $current->access_token !== $connection->access_token
                || $current->history_id !== null) {
                return $current;
            }

            $current->forceFill(['history_id' => $profile->historyId])->save();

            return $current;
        });
    }

    private function synchronizeInitialWindow(GmailConnection $connection): void
    {
        $afterEpochSeconds = $connection->connected_at
            ->subMinutes(self::INITIAL_SCAN_OVERLAP_MINUTES)
            ->getTimestamp();
        $messageIds = $this->messageIdsAfter(
            $connection->access_token,
            $afterEpochSeconds,
        );
        $messageIdsReceivedSinceConnection = [];

        foreach ($messageIds as $messageId) {
            $identity = $this->gmail->messageIdentity(
                $connection->access_token,
                $messageId,
            );

            if ($identity->receivedAt->greaterThanOrEqualTo($connection->connected_at)) {
                $messageIdsReceivedSinceConnection[] = $identity->messageId;
            }
        }

        $this->persistDiscoveredMessages(
            connection: $connection,
            messageIds: $messageIdsReceivedSinceConnection,
            historyId: $connection->history_id,
            completesInitialSync: true,
        );
    }

    private function synchronizeIncrementalHistory(GmailConnection $connection): void
    {
        if ($connection->history_id === null) {
            return;
        }

        $messageIds = [];
        $historyId = $connection->history_id;
        $pageToken = null;

        try {
            do {
                $page = $this->gmail->history(
                    accessToken: $connection->access_token,
                    startHistoryId: $connection->history_id,
                    pageToken: $pageToken,
                );

                foreach ($page->messageIds as $messageId) {
                    $messageIds[$messageId] = true;
                }

                $historyId = $page->historyId;
                $pageToken = $page->nextPageToken;
            } while ($pageToken !== null);
        } catch (GmailHistoryExpired) {
            $this->recoverExpiredHistory($connection);

            return;
        }

        $this->persistDiscoveredMessages(
            connection: $connection,
            messageIds: array_keys($messageIds),
            historyId: $historyId,
            completesInitialSync: false,
        );
    }

    private function recoverExpiredHistory(GmailConnection $connection): void
    {
        $profile = $this->gmail->profile($connection->access_token);

        if ($profile->accountIdentity !== $connection->gmail_account_identity) {
            throw GmailResponseInvalid::forOperation('profile identity');
        }

        $recoveryStartsAt = $connection->last_successful_sync_at
            ?->subMinutes(self::INITIAL_SCAN_OVERLAP_MINUTES)
            ?? $connection->connected_at;

        if ($recoveryStartsAt->lessThan($connection->connected_at)) {
            $recoveryStartsAt = $connection->connected_at;
        }

        $this->persistDiscoveredMessages(
            connection: $connection,
            messageIds: $this->messageIdsAfter(
                $connection->access_token,
                $recoveryStartsAt->getTimestamp(),
            ),
            historyId: $profile->historyId,
            completesInitialSync: false,
        );
    }

    private function reconcileRecentMessages(GmailConnection $connection): void
    {
        $this->persistDiscoveredMessages(
            connection: $connection,
            messageIds: $this->messageIdsAfter(
                $connection->access_token,
                now()->subDays(self::RECONCILIATION_DAYS)->getTimestamp(),
            ),
            historyId: $connection->history_id,
            completesInitialSync: false,
        );
    }

    /** @return list<string> */
    private function messageIdsAfter(
        string $accessToken,
        int $afterEpochSeconds,
    ): array {
        $messageIds = [];
        $pageToken = null;

        do {
            $page = $this->gmail->messagesAfter(
                accessToken: $accessToken,
                afterEpochSeconds: $afterEpochSeconds,
                pageToken: $pageToken,
            );

            foreach ($page->messageIds as $messageId) {
                $messageIds[$messageId] = true;
            }

            $pageToken = $page->nextPageToken;
        } while ($pageToken !== null);

        return array_keys($messageIds);
    }

    /** @param list<string> $messageIds */
    private function persistDiscoveredMessages(
        GmailConnection $connection,
        array $messageIds,
        ?string $historyId,
        bool $completesInitialSync,
    ): void {
        DB::transaction(function () use (
            $connection,
            $messageIds,
            $historyId,
            $completesInitialSync,
        ): void {
            $current = GmailConnection::query()
                ->lockForUpdate()
                ->findOrFail($connection->id);

            if ($current->ingestionIsPaused()
                || $current->history_id !== $connection->history_id
                || ($completesInitialSync && $current->initial_sync_completed_at !== null)) {
                return;
            }

            $timestamp = now();

            foreach ($messageIds as $messageId) {
                GmailMessageDiscovery::query()->insertOrIgnore([
                    'gmail_connection_id' => $current->id,
                    'message_id' => $messageId,
                    'processed_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            $current->forceFill([
                'history_id' => $historyId,
                'initial_sync_completed_at' => $completesInitialSync
                    ? $timestamp
                    : $current->initial_sync_completed_at,
                'last_successful_sync_at' => $timestamp,
            ])->save();
        });

        if ($messageIds === []) {
            return;
        }

        GmailMessageDiscovery::query()
            ->whereBelongsTo($connection, 'gmailConnection')
            ->whereIn('message_id', $messageIds)
            ->whereNull('processed_at')
            ->pluck('id')
            ->each(
                fn (int $discoveryId) => ProcessGmailMessage::dispatch($discoveryId),
            );
    }
}
