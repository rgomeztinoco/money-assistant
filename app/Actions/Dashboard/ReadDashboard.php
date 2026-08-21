<?php

namespace App\Actions\Dashboard;

use App\Actions\Ledger\CountOutstandingReviews;
use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Actions\Reporting\ReadSpendingAnalysis;
use App\Actions\Reporting\SpendingComparisonPeriod;
use App\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @phpstan-type SpendingComparisonData array{current_total_minor: string, previous_total_minor: string, change_minor: string, percentage_change: string|null, direction: 'increased'|'decreased'|'unchanged'|'no_baseline'|'no_activity'}
 * @phpstan-type CategoryInsightData array{category: array{id: int|null, name: string}, current_total_minor: string, previous_total_minor: string, change_minor: string}
 * @phpstan-type RecentTransactionData array{id: int, occurred_on: string, amount_minor: string, currency: string, kind: string, merchant_description: string}
 */
final class ReadDashboard
{
    public function __construct(
        private ReadRecentTransactions $readRecentTransactions,
        private CountOutstandingReviews $countOutstandingReviews,
        private ReadGmailConnectionStatus $readGmailConnectionStatus,
        private ReadSpendingAnalysis $readSpendingAnalysis,
    ) {}

    /**
     * @return array{
     *     period: array{label: string, date_from: string, date_to: string},
     *     comparison_period: array{label: string, date_from: string, date_to: string},
     *     spending: array{
     *         totals: array{PEN: string, USD: string},
     *         comparisons: array{PEN: SpendingComparisonData, USD: SpendingComparisonData},
     *         category_insights: array{PEN: list<CategoryInsightData>, USD: list<CategoryInsightData>}
     *     },
     *     review_queue: array{outstanding_count: int},
     *     recent_transactions: list<RecentTransactionData>,
     *     gmail: array{state: 'disconnected'|'connected'|'stale'|'check_failed'|'reauthorization_required', account_identity: string|null, last_successful_sync_at: string|null}
     * }
     */
    public function handle(User $owner): array
    {
        $today = CarbonImmutable::today();
        $comparisonPeriod = SpendingComparisonPeriod::monthToDate($today);
        $gmailStatus = $this->readGmailConnectionStatus->handle($owner);
        $penAnalysis = $this->readSpendingAnalysis->handle(
            owner: $owner,
            currency: Currency::Pen,
            period: $comparisonPeriod,
        );
        $usdAnalysis = $this->readSpendingAnalysis->handle(
            owner: $owner,
            currency: Currency::Usd,
            period: $comparisonPeriod,
        );

        return [
            'period' => [
                'label' => $today->isoFormat('MMMM YYYY'),
                'date_from' => $comparisonPeriod->currentDateFrom->toDateString(),
                'date_to' => $today->toDateString(),
            ],
            'comparison_period' => [
                'label' => $comparisonPeriod->previousDateFrom->isoFormat('MMMM D').' – '.$comparisonPeriod->previousDateTo->isoFormat('MMMM D, YYYY'),
                'date_from' => $comparisonPeriod->previousDateFrom->toDateString(),
                'date_to' => $comparisonPeriod->previousDateTo->toDateString(),
            ],
            'spending' => [
                'totals' => [
                    Currency::Pen->value => $penAnalysis['comparison']['current_total_minor'],
                    Currency::Usd->value => $usdAnalysis['comparison']['current_total_minor'],
                ],
                'comparisons' => [
                    Currency::Pen->value => $penAnalysis['comparison'],
                    Currency::Usd->value => $usdAnalysis['comparison'],
                ],
                'category_insights' => [
                    Currency::Pen->value => $penAnalysis['category_insights'],
                    Currency::Usd->value => $usdAnalysis['category_insights'],
                ],
            ],
            'review_queue' => [
                'outstanding_count' => $this->countOutstandingReviews->handle($owner),
            ],
            'recent_transactions' => $this->readRecentTransactions->handle($owner),
            'gmail' => [
                'state' => $gmailStatus['state'],
                'account_identity' => $gmailStatus['account_identity'],
                'last_successful_sync_at' => $gmailStatus['last_successful_sync_at'],
            ],
        ];
    }
}
