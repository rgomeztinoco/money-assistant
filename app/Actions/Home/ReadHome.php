<?php

namespace App\Actions\Home;

use App\Actions\Reporting\EquivalentPeriods;
use App\Actions\Reporting\NetSpendingAllocation;
use App\Actions\Reporting\ReadPeriodSummary;
use App\Actions\Reporting\ReportingPeriod;
use App\Currency;
use App\DataSources\ReadRecordedCoverage;
use App\ExactInteger;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * @phpstan-type AnalysisPeriod array{date_from: string, date_to: string}
 * @phpstan-type Coverage array{date_from: string, date_to: string, transaction_count: int}
 * @phpstan-type Summary array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string}
 * @phpstan-type PulseEvidence array{id: int, description: string, occurred_on: string, amount_minor: string, period: 'current'|'previous', absolute_amount: ExactInteger}
 * @phpstan-type PulseSignalBucket array{category: array{id: int|null, name: string}, amounts: array<int, ExactInteger>, transaction_counts: array<int, int>, evidence: list<PulseEvidence>}
 * @phpstan-type PulseSignal array{category: array{id: int|null, name: string}, current_total_minor: string, previous_total_minor: string, change_minor: string, current_transaction_count: int, previous_transaction_count: int, evidence: list<array{id: int, description: string, occurred_on: string, amount_minor: string, period: 'current'|'previous'}>}
 * @phpstan-type PulsePoint array{day: int, current_minor: string|null, previous_minor: string|null}
 * @phpstan-type Pulse array{previous_period: AnalysisPeriod, previous_net_spending_minor: string, change_minor: string, percentage_change: int|null, daily_net_spending: list<PulsePoint>, signals: list<PulseSignal>}
 * @phpstan-type Briefing array{currency: string, period: AnalysisPeriod, coverage: Coverage, summary: Summary, pulse: Pulse|null, input_request: array{transaction_count: int}|null}
 */
final class ReadHome
{
    public function __construct(
        private ReadPeriodSummary $readPeriodSummary,
        private NetSpendingAllocation $netSpendingAllocation,
        private ReadRecordedCoverage $readRecordedCoverage,
    ) {}

    /**
     * @param  array{currency?: string, period?: string, anchor?: string, preset?: string, date_from?: string, date_to?: string}  $filters
     * @return array{currency_filter: string|null, period: array{unit: string, anchor: string, date_from: string, date_to: string}, primary: Briefing|null, secondary: Briefing|null, today: string}
     */
    public function handle(User $owner, array $filters = []): array
    {
        $currencyFilter = isset($filters['currency'])
            ? Currency::from($filters['currency'])
            : null;
        $currencies = $currencyFilter === null
            ? [Currency::Pen, Currency::Usd]
            : [$currencyFilter];
        $today = CarbonImmutable::today(config('app.timezone'));
        $selectedPeriod = $this->reportingPeriod($owner, $currencies, $filters, $today);
        $analysisPeriod = $selectedPeriod->elapsedThrough($today);
        $briefings = array_values(array_filter(array_map(
            fn (Currency $currency): ?array => $this->briefing(
                $owner,
                $currency,
                $selectedPeriod,
                $analysisPeriod,
            ),
            $currencies,
        )));

        return [
            'currency_filter' => $currencyFilter?->value,
            'period' => $selectedPeriod->data(),
            'primary' => $briefings[0] ?? null,
            'secondary' => $briefings[1] ?? null,
            'today' => $today->toDateString(),
        ];
    }

    /** @return Briefing|null */
    private function briefing(
        User $owner,
        Currency $currency,
        ReportingPeriod $selectedPeriod,
        ?ReportingPeriod $analysisPeriod,
    ): ?array {
        if ($analysisPeriod === null) {
            return null;
        }

        $dateFrom = $analysisPeriod->dateFrom;
        $dateTo = $analysisPeriod->dateTo;
        $coverageQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()]);
        $coverage = $coverageQuery
            ->toBase()
            ->selectRaw('min(occurred_on) as date_from, max(occurred_on) as date_to, count(*) as transaction_count')
            ->first();

        $transactionCount = (int) $coverage->transaction_count;

        if ($transactionCount === 0 && ! $this->hasPreviousNetSpendingActivity($owner, $currency, $analysisPeriod)) {
            return null;
        }

        $summary = $this->readPeriodSummary->handle($owner, $currency, $dateFrom, $dateTo);

        return [
            'currency' => $currency->value,
            'period' => $selectedPeriod->data(),
            'coverage' => [
                'date_from' => $coverage->date_from === null ? $dateFrom->toDateString() : (string) $coverage->date_from,
                'date_to' => $coverage->date_to === null ? $dateTo->toDateString() : (string) $coverage->date_to,
                'transaction_count' => $transactionCount,
                'source' => $this->readRecordedCoverage->handle($owner, $dateFrom, $dateTo),
            ],
            'summary' => $summary,
            'pulse' => $this->pulse(
                $owner,
                $currency,
                $selectedPeriod,
                $analysisPeriod,
                $summary,
            ),
            'input_request' => $this->inputRequest($owner, $currency, $dateFrom, $dateTo),
        ];
    }

    private function hasPreviousNetSpendingActivity(
        User $owner,
        Currency $currency,
        ReportingPeriod $reportingPeriod,
    ): bool {
        $previousPeriod = EquivalentPeriods::forPeriod($reportingPeriod, 1)->comparisons()[0];

        return Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->whereBetween('occurred_on', [
                $previousPeriod[0]->toDateString(),
                $previousPeriod[1]->toDateString(),
            ])
            ->exists();
    }

    /**
     * @param  list<Currency>  $currencies
     * @param  array{currency?: string, period?: string, anchor?: string, preset?: string, date_from?: string, date_to?: string}  $filters
     */
    private function reportingPeriod(
        User $owner,
        array $currencies,
        array $filters,
        CarbonImmutable $today,
    ): ReportingPeriod {
        if (Arr::hasAny($filters, ['period', 'anchor', 'preset', 'date_from', 'date_to'])) {
            return ReportingPeriod::fromFilters($filters);
        }

        $latestOccurredOn = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereIn('currency', array_map(
                fn (Currency $currency): string => $currency->value,
                $currencies,
            ))
            ->whereNull('voided_at')
            ->whereDate('occurred_on', '<=', $today->toDateString())
            ->max('occurred_on');

        if (! is_string($latestOccurredOn)) {
            return ReportingPeriod::fromFilters([]);
        }

        $latestDate = CarbonImmutable::parse($latestOccurredOn, config('app.timezone'));

        return ReportingPeriod::fromFilters([
            'period' => 'month',
            'anchor' => $latestDate->toDateString(),
        ]);
    }

    /** @return array{transaction_count: int}|null */
    private function inputRequest(
        User $owner,
        Currency $currency,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): ?array {
        $transactionCount = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->whereRequiresReview()
            ->count();

        return $transactionCount === 0 ? null : ['transaction_count' => $transactionCount];
    }

    /**
     * @param  Summary  $summary
     * @return Pulse
     */
    private function pulse(
        User $owner,
        Currency $currency,
        ReportingPeriod $selectedPeriod,
        ReportingPeriod $analysisPeriod,
        array $summary,
    ): array {
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->get(['id', 'parent_id', 'name']);
        $categoriesById = $categories->keyBy('id');
        $comparison = EquivalentPeriods::forPeriod($analysisPeriod, 1);
        $periods = $comparison->all();

        /** @var array<string, PulseSignalBucket> $signalBuckets */
        $signalBuckets = [];
        /** @var array<int, array<int, ExactInteger>> $dailyAmounts */
        $dailyAmounts = [];
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->whereBetween('occurred_on', [
                $periods[1][0]->toDateString(),
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

            $day = (int) $periods[$periodIndex][0]->diffInDays($transaction->occurred_on) + 1;
            $transactionAmount = $transaction->kind->netSpendingAmount($transaction->amount_minor);
            $dailyAmounts[$periodIndex][$day] = ($dailyAmounts[$periodIndex][$day] ?? ExactInteger::from(0))
                ->add($transactionAmount);

            foreach ($this->netSpendingAllocation->byTopLevelCategory($transaction, $categoriesById) as $categoryKey => $allocation) {
                $bucketKey = (string) $categoryKey;
                $category = $categoryKey === 'uncategorized'
                    ? ['id' => null, 'name' => 'Uncategorized']
                    : [
                        'id' => $categoriesById->get((int) $categoryKey)->id,
                        'name' => $categoriesById->get((int) $categoryKey)->name,
                    ];
                $signalBuckets[$bucketKey] ??= [
                    'category' => $category,
                    'amounts' => [],
                    'transaction_counts' => [],
                    'evidence' => [],
                ];
                $signalBuckets[$bucketKey]['amounts'][$periodIndex] = ($signalBuckets[$bucketKey]['amounts'][$periodIndex] ?? ExactInteger::from(0))
                    ->add($allocation);
                $signalBuckets[$bucketKey]['transaction_counts'][$periodIndex] = ($signalBuckets[$bucketKey]['transaction_counts'][$periodIndex] ?? 0) + 1;

                $signalBuckets[$bucketKey]['evidence'][] = [
                    'id' => $transaction->id,
                    'description' => $transaction->description,
                    'occurred_on' => $transaction->occurred_on->toDateString(),
                    'amount_minor' => $allocation->value(),
                    'period' => $periodIndex === 0 ? 'current' : 'previous',
                    'absolute_amount' => $this->absolute($allocation->value()),
                ];
            }
        }

        $signals = [];

        foreach ($signalBuckets as $bucket) {
            $current = $bucket['amounts'][0] ?? ExactInteger::from(0);
            $previous = $bucket['amounts'][1] ?? ExactInteger::from(0);
            $change = $current->subtract($previous);

            if ($change->compare(ExactInteger::from(0)) === 0) {
                continue;
            }

            usort($bucket['evidence'], fn (array $left, array $right): int => $right['absolute_amount']->compare($left['absolute_amount']));
            $evidence = array_map(
                fn (array $item): array => Arr::except($item, 'absolute_amount'),
                Arr::take($bucket['evidence'], 4),
            );
            $signals[] = [
                'category' => $bucket['category'],
                'current_total_minor' => $current->value(),
                'previous_total_minor' => $previous->value(),
                'change_minor' => $change->value(),
                'current_transaction_count' => $bucket['transaction_counts'][0] ?? 0,
                'previous_transaction_count' => $bucket['transaction_counts'][1] ?? 0,
                'evidence' => $evidence,
            ];
        }

        usort($signals, fn (array $left, array $right): int => $this->absolute($right['change_minor'])
            ->compare($this->absolute($left['change_minor'])));
        $previousSummary = $this->readPeriodSummary->handle(
            $owner,
            $currency,
            $periods[1][0],
            $periods[1][1],
        );
        $change = ExactInteger::from($summary['net_spending_minor'])
            ->subtract(ExactInteger::from($previousSummary['net_spending_minor']));

        return [
            'previous_period' => $this->analysisPeriod($periods[1][0], $periods[1][1]),
            'previous_net_spending_minor' => $previousSummary['net_spending_minor'],
            'change_minor' => $change->value(),
            'percentage_change' => $this->percentageChange(
                $change,
                ExactInteger::from($previousSummary['net_spending_minor']),
            ),
            'daily_net_spending' => $this->dailyNetSpending(
                $selectedPeriod,
                $periods,
                $dailyAmounts,
            ),
            'signals' => array_values(Arr::take($signals, 3)),
        ];
    }

    /**
     * @param  non-empty-list<array{CarbonImmutable, CarbonImmutable}>  $periods
     * @param  array<int, array<int, ExactInteger>>  $dailyAmounts
     * @return list<PulsePoint>
     */
    private function dailyNetSpending(
        ReportingPeriod $selectedPeriod,
        array $periods,
        array $dailyAmounts,
    ): array {
        $currentDays = (int) $periods[0][0]->diffInDays($periods[0][1]) + 1;
        $previousDays = (int) $periods[1][0]->diffInDays($periods[1][1]) + 1;
        $selectedDays = (int) $selectedPeriod->dateFrom->diffInDays($selectedPeriod->dateTo) + 1;
        $currentTotal = ExactInteger::from(0);
        $previousTotal = ExactInteger::from(0);
        $points = [[
            'day' => 0,
            'current_minor' => '0',
            'previous_minor' => '0',
        ]];

        foreach (range(1, max($selectedDays, $currentDays, $previousDays)) as $day) {
            $currentTotal = $currentTotal->add($dailyAmounts[0][$day] ?? ExactInteger::from(0));
            $previousTotal = $previousTotal->add($dailyAmounts[1][$day] ?? ExactInteger::from(0));
            $points[] = [
                'day' => $day,
                'current_minor' => $day <= $currentDays ? $currentTotal->value() : null,
                'previous_minor' => $day <= $previousDays ? $previousTotal->value() : null,
            ];
        }

        return $points;
    }

    private function percentageChange(ExactInteger $change, ExactInteger $previous): ?int
    {
        if ($previous->compare(ExactInteger::from(0)) !== 1) {
            return null;
        }

        return (int) round((float) bcdiv(
            bcmul($change->value(), '100', 2),
            $previous->value(),
            2,
        ));
    }

    /** @return AnalysisPeriod */
    private function analysisPeriod(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        return [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
        ];
    }

    private function absolute(string $amount): ExactInteger
    {
        $exactAmount = ExactInteger::from($amount);

        return $exactAmount->compare(ExactInteger::from(0)) === -1
            ? ExactInteger::from(0)->subtract($exactAmount)
            : $exactAmount;
    }
}
