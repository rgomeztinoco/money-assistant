<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailReauthorizationRequired;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\User;
use App\SpendingNotificationProcessingOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class ReadGmailReview
{
    private const int PAGE_SIZE = 20;

    public function __construct(
        private Gmail $gmail,
        private RefreshGmailConnection $refreshGmailConnection,
    ) {}

    /**
     * @return array{
     *     view: 'all'|'unrecognized'|'failed'|'dismissed',
     *     attention_count: int,
     *     unrecognized_count: int,
     *     failed_count: int,
     *     dismissed_count: int,
     *     items: LengthAwarePaginator<int, array<string, mixed>>
     * }
     */
    public function handle(User $owner, string $requestedView): array
    {
        $view = in_array($requestedView, ['all', 'unrecognized', 'failed', 'dismissed'], true)
            ? $requestedView
            : 'all';
        $connection = GmailConnection::query()->whereBelongsTo($owner, 'owner')->first();

        if ($connection === null) {
            return [
                'view' => $view,
                'attention_count' => 0,
                'unrecognized_count' => 0,
                'failed_count' => 0,
                'dismissed_count' => 0,
                'items' => new LengthAwarePaginator([], 0, self::PAGE_SIZE, 1),
            ];
        }

        $query = GmailMessageDiscovery::query()
            ->where('gmail_connection_id', $connection->id);
        $unrecognizedCount = $this->unrecognized((clone $query)->whereNull('dismissed_at'))->count();
        $failedCount = $this->failed((clone $query)->whereNull('dismissed_at'))->count();
        $dismissedCount = (clone $query)->whereNotNull('dismissed_at')->count();

        $itemsQuery = match ($view) {
            'unrecognized' => $this->unrecognized($query->whereNull('dismissed_at')),
            'failed' => $this->failed($query->whereNull('dismissed_at')),
            'dismissed' => $query->whereNotNull('dismissed_at'),
            default => $this->unresolved($query->whereNull('dismissed_at')),
        };
        $page = $itemsQuery
            ->with('reference')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        $metadataState = $connection->ingestionIsPaused() ? 'reauthorization_required' : null;

        if ($page->isNotEmpty() && $metadataState === null && $connection->access_token_expires_at->lessThanOrEqualTo(now()->addMinute())) {
            try {
                $connection = $this->refreshGmailConnection->handle($connection);
                if ($connection->ingestionIsPaused()) {
                    $metadataState = 'reauthorization_required';
                }
            } catch (GmailReauthorizationRequired) {
                $metadataState = 'reauthorization_required';
            } catch (GmailRequestFailed) {
                $metadataState = 'unavailable';
            }
        }

        $page->through(function (GmailMessageDiscovery $discovery) use ($connection, $metadataState): array {
            $reference = $discovery->reference;
            $resolved = $reference?->transaction_id !== null;
            $failed = $discovery->processing_failed_at !== null
                || in_array($reference?->processing_outcome, [
                    SpendingNotificationProcessingOutcome::Failed->value,
                    SpendingNotificationProcessingOutcome::AuthenticationFailed->value,
                ], true);
            $outcome = $resolved ? 'resolved' : ($failed ? 'failed' : 'unrecognized');
            $explanation = match (true) {
                $resolved => 'A Transaction was created.',
                $discovery->processing_failed_at !== null => 'Message processing stopped after repeated attempts.',
                $reference?->processing_outcome === SpendingNotificationProcessingOutcome::AuthenticationFailed->value => 'The sender could not be verified.',
                $failed => 'The message could not be parsed.',
                default => 'No supported format matched. The precise reason is unknown.',
            };
            $summary = null;
            $summaryState = $metadataState;

            if ($summaryState === null) {
                try {
                    $summary = $this->gmail->messageSummary($connection->access_token, $discovery->message_id);
                    $summaryState = $summary->messageId === $discovery->message_id ? 'available' : 'unavailable';
                } catch (GmailRequestFailed $exception) {
                    $summaryState = match ($exception->httpStatus()) {
                        404 => 'missing',
                        401, 403 => 'reauthorization_required',
                        default => 'unavailable',
                    };
                }
            }

            return [
                'id' => $discovery->id,
                'sender' => $summaryState === 'available' ? $summary->fromAddress : null,
                'subject' => $summaryState === 'available' ? $summary->subject : null,
                'received_at' => $summaryState === 'available' ? $summary->receivedAt->toIso8601String() : null,
                'summary_state' => $summaryState,
                'outcome' => $outcome,
                'explanation' => $explanation,
                'dismissed_at' => $discovery->dismissed_at?->toIso8601String(),
                'retryable' => $discovery->dismissed_at === null
                    && ! $connection->ingestionIsPaused()
                    && ! $resolved
                    && ($discovery->processing_failed_at !== null
                        ? $discovery->processed_at === null && $discovery->failed_job_uuid !== null
                        : $reference?->isRetryable() === true),
                'gmail_url' => $summaryState === 'available'
                    ? 'https://mail.google.com/mail/u/'
                        .rawurlencode($connection->gmail_account_identity)
                        .'/#all/'
                        .rawurlencode($summary->threadId)
                    : null,
            ];
        });

        return [
            'view' => $view,
            'attention_count' => $unrecognizedCount + $failedCount,
            'unrecognized_count' => $unrecognizedCount,
            'failed_count' => $failedCount,
            'dismissed_count' => $dismissedCount,
            'items' => $page,
        ];
    }

    /** @param Builder<GmailMessageDiscovery> $query
     * @return Builder<GmailMessageDiscovery>
     */
    private function unresolved(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereHas('reference', fn (Builder $reference) => $reference
                ->whereNull('transaction_id')
                ->whereIn('processing_outcome', [
                    SpendingNotificationProcessingOutcome::Unsupported->value,
                    SpendingNotificationProcessingOutcome::Failed->value,
                    SpendingNotificationProcessingOutcome::AuthenticationFailed->value,
                ]))
            ->orWhere(fn (Builder $query) => $query
                ->whereNotNull('processing_failed_at')
                ->whereDoesntHave('reference', fn (Builder $reference) => $reference
                    ->whereNotNull('transaction_id'))));
    }

    /** @param Builder<GmailMessageDiscovery> $query
     * @return Builder<GmailMessageDiscovery>
     */
    private function unrecognized(Builder $query): Builder
    {
        return $query
            ->whereNull('processing_failed_at')
            ->whereHas('reference', fn (Builder $reference) => $reference
                ->whereNull('transaction_id')
                ->where('processing_outcome', SpendingNotificationProcessingOutcome::Unsupported->value));
    }

    /** @param Builder<GmailMessageDiscovery> $query
     * @return Builder<GmailMessageDiscovery>
     */
    private function failed(Builder $query): Builder
    {
        return $this->unresolved($query)->where(fn (Builder $query) => $query
            ->whereNotNull('processing_failed_at')
            ->orWhereHas('reference', fn (Builder $reference) => $reference
                ->whereIn('processing_outcome', [
                    SpendingNotificationProcessingOutcome::Failed->value,
                    SpendingNotificationProcessingOutcome::AuthenticationFailed->value,
                ])));
    }
}
