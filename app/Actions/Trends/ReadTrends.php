<?php

namespace App\Actions\Trends;

use App\Actions\Reporting\EquivalentPeriods;
use App\Actions\Reporting\NetSpendingAllocation;
use App\Actions\Reporting\ReadPeriodSummary;
use App\Actions\Reporting\ReportingPeriod;
use App\Currency;
use App\ExactInteger;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * @phpstan-type TrendPeriod array{unit: string, label: string, anchor: string, date_from: string, date_to: string}
 * @phpstan-type TrendComparisonPeriod array{label: string, date_from: string, date_to: string}
 * @phpstan-type TrendFindingBase array{currency: string, current_total_minor: string, typical_total_minor: string, change_minor: string, period_totals_minor: list<string>, current_transaction_count: int, typical_transaction_count: int, unusual_transaction: array{id: int, description: string, amount_minor: string}|null, scenario: array{difference_minor: string}|null}
 * @phpstan-type TrendCategoryFinding array{kind: 'category', category: array{id: int|null, name: string}, currency: string, current_total_minor: string, typical_total_minor: string, change_minor: string, period_totals_minor: list<string>, current_transaction_count: int, typical_transaction_count: int, unusual_transaction: array{id: int, description: string, amount_minor: string}|null, scenario: array{difference_minor: string}|null}
 * @phpstan-type TrendMerchantFinding array{kind: 'merchant', merchant: string, currency: string, current_total_minor: string, typical_total_minor: string, change_minor: string, period_totals_minor: list<string>, current_transaction_count: int, typical_transaction_count: int, unusual_transaction: array{id: int, description: string, amount_minor: string}|null, scenario: array{difference_minor: string}|null}
 * @phpstan-type TrendFinding TrendCategoryFinding|TrendMerchantFinding
 * @phpstan-type TrendEvidence array<int, array{id: int, description: string, amount_minor: string, absolute_amount: ExactInteger}>
 * @phpstan-type TrendBucket array{amounts: array<int, ExactInteger>, counts: array<int, int>, largest: TrendEvidence}
 * @phpstan-type TrendCategoryBucket array{category: array{id: int|null, name: string}, amounts: array<int, ExactInteger>, counts: array<int, int>, largest: TrendEvidence}
 * @phpstan-type TrendMerchantBucket array{merchant: string, amounts: array<int, ExactInteger>, counts: array<int, int>, largest: TrendEvidence}
 */
final class ReadTrends
{
    private const int PreviousPeriodCount = 6;

    public function __construct(
        private ReadPeriodSummary $readPeriodSummary,
        private MerchantNormalizer $merchantNormalizer,
        private NetSpendingAllocation $netSpendingAllocation,
    ) {}

    /**
     * @param  array{currency?: string, period?: string, anchor?: string, preset?: string, date_from?: string, date_to?: string}  $filters
     * @return array{
     *     currency_filter: string|null,
     *     currency: string,
     *     period: TrendPeriod,
     *     comparison_periods: list<TrendComparisonPeriod>,
     *     summary: array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string}|null,
     *     findings: list<TrendFinding>,
     *     monthly_context: list<array{month: string, label: string, date_from: string, date_to: string, total_minor: string|null}>,
     *     secondary: array{currency: string, summary: array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string}|null, findings: list<TrendFinding>, monthly_context: list<array{month: string, label: string, date_from: string, date_to: string, total_minor: string|null}>}|null,
     *     today: string
     * }
     */
    public function handle(User $owner, ?Currency $currency, array $filters = []): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $reportingPeriod = ReportingPeriod::fromFilters($filters)->endingNoLaterThan($today);
        $comparison = EquivalentPeriods::forPeriod(
            $reportingPeriod,
            self::PreviousPeriodCount,
        );
        $periods = $comparison->all();
        $contextDateTo = $reportingPeriod->dateTo;
        $contextDateFrom = $contextDateTo->startOfMonth()->subMonthsNoOverflow(self::PreviousPeriodCount)->startOfMonth();
        $primaryCurrency = $currency ?? Currency::Pen;
        $primary = $this->readCurrency(
            $owner,
            $primaryCurrency,
            $comparison,
            $contextDateFrom,
            $contextDateTo,
        );
        $secondary = $currency === null
            ? $this->readCurrency(
                $owner,
                Currency::Usd,
                $comparison,
                $contextDateFrom,
                $contextDateTo,
            )
            : null;

        return [
            'currency_filter' => $currency?->value,
            ...$primary,
            'period' => $reportingPeriod->data(),
            'comparison_periods' => array_map(
                fn (array $period): array => $this->periodData($period[0], $period[1]),
                array_slice($periods, 1),
            ),
            'secondary' => $secondary,
            'today' => $today->toDateString(),
        ];
    }

    /**
     * @return array{currency: string, summary: array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string}|null, findings: list<TrendFinding>, monthly_context: list<array{month: string, label: string, date_from: string, date_to: string, total_minor: string|null}>}
     */
    private function readCurrency(
        User $owner,
        Currency $currency,
        EquivalentPeriods $comparison,
        CarbonImmutable $contextDateFrom,
        CarbonImmutable $contextDateTo,
    ): array {
        $currentPeriod = $comparison->all()[0];
        $hasContextActivity = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$contextDateFrom->toDateString(), $contextDateTo->toDateString()])
            ->exists();
        $hasCurrentActivity = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$currentPeriod[0]->toDateString(), $currentPeriod[1]->toDateString()])
            ->exists();

        return [
            'currency' => $currency->value,
            'summary' => $hasCurrentActivity
                ? $this->readPeriodSummary->handle($owner, $currency, $currentPeriod[0], $currentPeriod[1])
                : null,
            'findings' => $hasCurrentActivity
                ? $this->findings($owner, $currency, $comparison)
                : [],
            'monthly_context' => $hasContextActivity
                ? $this->monthlyContext($owner, $currency, $contextDateTo)
                : [],
        ];
    }

    /** @return TrendComparisonPeriod */
    private function periodData(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        return [
            'label' => $dateFrom->isoFormat('MMM D').' – '.$dateTo->isoFormat('MMM D, YYYY'),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
        ];
    }

    /**
     * @return list<TrendFinding>
     */
    private function findings(
        User $owner,
        Currency $currency,
        EquivalentPeriods $comparison,
    ): array {
        $periods = $comparison->all();
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->get(['id', 'parent_id', 'name']);
        $categoriesById = $categories->keyBy('id');

        /** @var array<string, TrendCategoryBucket> $categoryBuckets */
        $categoryBuckets = [];
        /** @var array<string, TrendMerchantBucket> $merchantBuckets */
        $merchantBuckets = [];
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->whereBetween('occurred_on', [
                Arr::last($periods)[0]->toDateString(),
                $periods[0][1]->toDateString(),
            ])
            ->select(['id', 'occurred_on', 'amount_minor', 'kind', 'category_id', 'description'])
            ->with([
                'receiptBreakdown:id,transaction_id',
                'receiptBreakdown.lineItems:id,receipt_breakdown_id,category_id,line_total_minor',
            ])
            ->get();

        foreach ($transactions as $transaction) {
            $periodIndex = $comparison->indexOf($transaction->occurred_on);

            if ($periodIndex === null) {
                continue;
            }

            foreach ($this->netSpendingAllocation->byTopLevelCategory($transaction, $categoriesById) as $categoryKey => $allocation) {
                $category = $categoryKey === 'uncategorized'
                    ? ['id' => null, 'name' => 'Uncategorized']
                    : [
                        'id' => $categoriesById->get((int) $categoryKey)->id,
                        'name' => $categoriesById->get((int) $categoryKey)->name,
                    ];
                $bucketKey = (string) $categoryKey;
                $categoryBuckets[$bucketKey] ??= [
                    'category' => $category,
                    'amounts' => [],
                    'counts' => [],
                    'largest' => [],
                ];
                $categoryBuckets[$bucketKey] = [
                    ...$categoryBuckets[$bucketKey],
                    ...$this->recordEvidence($categoryBuckets[$bucketKey], $periodIndex, $allocation, $transaction),
                ];
            }

            $merchantKey = $this->merchantNormalizer->normalize($transaction->description);
            $merchantBuckets[$merchantKey] ??= [
                'merchant' => $transaction->description,
                'amounts' => [],
                'counts' => [],
                'largest' => [],
            ];
            $merchantBuckets[$merchantKey] = [
                ...$merchantBuckets[$merchantKey],
                ...$this->recordEvidence(
                    $merchantBuckets[$merchantKey],
                    $periodIndex,
                    $transaction->kind->netSpendingAmount($transaction->amount_minor),
                    $transaction,
                ),
            ];
        }

        $findings = [
            ...array_map(
                fn (array $bucket): array => [
                    'kind' => 'category',
                    'category' => $bucket['category'],
                    ...$this->findingBase($bucket, $currency, $comparison),
                ],
                array_values($categoryBuckets),
            ),
            ...array_map(
                fn (array $bucket): array => [
                    'kind' => 'merchant',
                    'merchant' => $bucket['merchant'],
                    ...$this->findingBase($bucket, $currency, $comparison),
                ],
                array_values($merchantBuckets),
            ),
        ];
        $findings = array_values(array_filter(
            $findings,
            fn (array $finding): bool => $finding['change_minor'] !== '0',
        ));
        usort($findings, function (array $left, array $right): int {
            $impact = $this->absolute($right['change_minor'])
                ->compare($this->absolute($left['change_minor']));

            if ($impact !== 0) {
                return $impact;
            }

            return $left['kind'] === $right['kind']
                ? 0
                : ($left['kind'] === 'category' ? -1 : 1);
        });
        $findings = array_values(Arr::take($findings, 6));

        if (isset($findings[0]) && ! str_starts_with($findings[0]['change_minor'], '-')) {
            $findings[0]['scenario'] = ['difference_minor' => $findings[0]['change_minor']];
        }

        return $findings;
    }

    /**
     * @param  TrendBucket  $bucket
     * @return TrendBucket
     */
    private function recordEvidence(
        array $bucket,
        int $periodIndex,
        ExactInteger $amount,
        Transaction $transaction,
    ): array {
        $bucket['amounts'][$periodIndex] = ($bucket['amounts'][$periodIndex] ?? ExactInteger::from(0))
            ->add($amount);
        $bucket['counts'][$periodIndex] = ($bucket['counts'][$periodIndex] ?? 0) + 1;
        $absoluteAmount = $this->absolute($amount->value());
        $largest = $bucket['largest'][$periodIndex] ?? null;

        if ($largest === null || $absoluteAmount->compare($largest['absolute_amount']) === 1) {
            $bucket['largest'][$periodIndex] = [
                'id' => $transaction->id,
                'description' => $transaction->description,
                'amount_minor' => $amount->value(),
                'absolute_amount' => $absoluteAmount,
            ];
        }

        return $bucket;
    }

    /**
     * @param  TrendBucket  $bucket
     * @return TrendFindingBase
     */
    private function findingBase(
        array $bucket,
        Currency $currency,
        EquivalentPeriods $comparison,
    ): array {
        $current = $bucket['amounts'][0] ?? ExactInteger::from(0);
        $typical = $comparison->typicalAmount($bucket['amounts']);
        $currentLargest = $bucket['largest'][0] ?? null;
        $previousLargest = ExactInteger::from(0);

        foreach ($comparison->comparisonIndexes() as $index) {
            $candidate = $bucket['largest'][$index]['absolute_amount'] ?? ExactInteger::from(0);

            if ($candidate->compare($previousLargest) === 1) {
                $previousLargest = $candidate;
            }
        }

        $unusualTransaction = $currentLargest !== null
            && $currentLargest['absolute_amount']->compare($previousLargest) === 1
                ? [
                    'id' => $currentLargest['id'],
                    'description' => $currentLargest['description'],
                    'amount_minor' => $currentLargest['amount_minor'],
                ]
                : null;

        $comparisonCount = $comparison->comparisonCount();

        return [
            'currency' => $currency->value,
            'current_total_minor' => $current->value(),
            'typical_total_minor' => $typical->value(),
            'change_minor' => $current->subtract($typical)->value(),
            'period_totals_minor' => array_map(
                fn (int $index): string => ($bucket['amounts'][$index] ?? ExactInteger::from(0))->value(),
                array_reverse(array_keys($comparison->all())),
            ),
            'current_transaction_count' => $bucket['counts'][0] ?? 0,
            'typical_transaction_count' => intdiv(
                array_sum(array_map(
                    fn (int $index): int => $bucket['counts'][$index] ?? 0,
                    $comparison->comparisonIndexes(),
                )),
                $comparisonCount,
            ),
            'unusual_transaction' => $unusualTransaction,
            'scenario' => null,
        ];
    }

    /**
     * @return list<array{month: string, label: string, date_from: string, date_to: string, total_minor: string|null}>
     */
    private function monthlyContext(User $owner, Currency $currency, CarbonImmutable $contextDateTo): array
    {
        $dateFrom = $contextDateTo->startOfMonth()->subMonthsNoOverflow(self::PreviousPeriodCount)->startOfMonth();

        /** @var array<string, array{amount: ExactInteger, transaction_count: int}> $months */
        $months = [];
        $month = $dateFrom;

        while ($month->lessThanOrEqualTo($contextDateTo)) {
            $months[$month->format('Y-m')] = [
                'amount' => ExactInteger::from(0),
                'transaction_count' => 0,
            ];
            $month = $month->addMonth();
        }

        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $contextDateTo->toDateString()])
            ->select(['id', 'occurred_on', 'amount_minor', 'kind'])
            ->cursor();

        foreach ($transactions as $transaction) {
            $monthKey = $transaction->occurred_on->format('Y-m');
            $monthData = $months[$monthKey];
            $months[$monthKey] = [
                'transaction_count' => $monthData['transaction_count'] + 1,
                'amount' => $monthData['amount']->add(
                    $transaction->kind->netSpendingAmount($transaction->amount_minor),
                ),
            ];
        }

        return array_map(function (string $monthKey, array $monthData) use ($contextDateTo): array {
            $month = CarbonImmutable::parse($monthKey.'-01', config('app.timezone'));
            $dateTo = $month->isSameMonth($contextDateTo) ? $contextDateTo : $month->endOfMonth();

            return [
                'month' => $monthKey,
                'label' => $month->isoFormat('MMM YYYY'),
                'date_from' => $month->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_minor' => $monthData['transaction_count'] === 0
                    ? null
                    : $monthData['amount']->value(),
            ];
        }, array_keys($months), array_values($months));
    }

    private function absolute(string $amount): ExactInteger
    {
        $exactAmount = ExactInteger::from($amount);

        return $exactAmount->compare(ExactInteger::from(0)) === -1
            ? ExactInteger::from(0)->subtract($exactAmount)
            : $exactAmount;
    }
}
