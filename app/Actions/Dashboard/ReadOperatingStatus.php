<?php

namespace App\Actions\Dashboard;

use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Actions\NotificationIngestion\ReadParserProfileHealthSummary;
use App\Actions\OpenClaw\ReadOpenClawStatus;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ReadOperatingStatus
{
    public function __construct(
        private ReadGmailConnectionStatus $readGmailConnectionStatus,
        private ReadParserProfileHealthSummary $readParserProfileHealthSummary,
        private ReadOpenClawStatus $readOpenClawStatus,
    ) {}

    /**
     * @return array{
     *     summary: array{
     *         gmail: string,
     *         openclaw: 'configured'|'unavailable',
     *         parser_profiles: array{healthy_count: int, degraded_count: int},
     *         daily_exchange_rates: array{attention_count: int}
     *     },
     *     exceptions: list<array<string, int|string|null>>
     * }
     */
    public function handle(User $owner): array
    {
        $gmail = $this->readGmailConnectionStatus->handle($owner);
        $parserProfiles = $this->readParserProfileHealthSummary->handle($owner);
        $openClaw = $this->readOpenClawStatus->handle();
        $exchangeRateRequests = DailyExchangeRateSeedRequest::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('completed_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('owner_entry_required_at')
                    ->orWhereNotNull('retrieval_failed_at');
            })
            ->orderBy('applicable_on')
            ->get(['applicable_on']);
        $exceptions = [];

        foreach ($parserProfiles['alerts'] as $alert) {
            $exceptions[] = [
                'type' => $alert['kind'] === 'security'
                    ? 'parser_security'
                    : 'parser_drift',
                'profile_id' => $alert['profile_id'],
                'profile_name' => $alert['profile_name'],
                'count' => $alert['count'],
            ];
        }

        foreach ($exchangeRateRequests as $exchangeRateRequest) {
            $exceptions[] = [
                'type' => 'missing_exchange_rate',
                'applicable_on' => $exchangeRateRequest->applicable_on->toDateString(),
            ];
        }

        if ($gmail['state'] !== 'connected') {
            $exceptions[] = [
                'type' => 'gmail_connection',
                'state' => $gmail['state'],
            ];
        }

        if ($openClaw['state'] === 'unavailable') {
            $exceptions[] = [
                'type' => 'openclaw_unavailable',
            ];
        }

        return [
            'summary' => [
                'gmail' => $gmail['state'],
                'openclaw' => $openClaw['state'],
                'parser_profiles' => [
                    'healthy_count' => $parserProfiles['healthy_count'],
                    'degraded_count' => $parserProfiles['degraded_count'],
                ],
                'daily_exchange_rates' => [
                    'attention_count' => $exchangeRateRequests->count(),
                ],
            ],
            'exceptions' => $exceptions,
        ];
    }
}
