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
 * @phpstan-type AnalysisPeriod array{label: string, date_from: string, date_to: string}
 * @phpstan-type Coverage array{date_from: string, date_to: string, transaction_count: int}
 * @phpstan-type Summary array{net_spending_minor: string, income_minor: string, moved_to_savings_minor: string}
 * @phpstan-type MaterialChange array{category: array{id: int|null, name: string}, current_total_minor: string, typical_total_minor: string, change_minor: string, comparison_periods: list<AnalysisPeriod>}
 * @phpstan-type Briefing array{currency: string, period: AnalysisPeriod, coverage: Coverage, summary: Summary, material_change: MaterialChange|null, input_request: array{transaction_count: int}|null}
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
     * @return array{currency_filter: string|null, period: array{unit: string, label: string, anchor: string, date_from: string, date_to: string}, primary: Briefing|null, secondary: Briefing|null, today: string}
     */
    public function handle(User $owner, array $filters = []): array
    {
        $currencyFilter = isset($filters['currency'])
            ? Currency::from($filters['currency'])
            : null;
        $currencies = $currencyFilter === null
            ? [Currency::Pen, Currency::Usd]
            : [$currencyFilter];
        $period = $this->reportingPeriod($owner, $currencies, $filters);
        $primaryCurrency = $currencyFilter ?? Currency::Pen;

        return [
            'currency_filter' => $currencyFilter?->value,
            'period' => $period->data(),
            'primary' => $this->briefing($owner, $primaryCurrency, $period, true),
            'secondary' => $currencyFilter === null
                ? $this->briefing($owner, Currency::Usd, $period, false)
                : null,
            'today' => CarbonImmutable::today(config('app.timezone'))->toDateString(),
        ];
    }

    /** @return Briefing|null */
    private function briefing(
        User $owner,
        Currency $currency,
        ReportingPeriod $period,
        bool $includeGuidance,
    ): ?array {
        $dateFrom = $period->dateFrom;
        $dateTo = $period->dateTo;
        $coverageQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()]);
        $coverage = $coverageQuery
            ->toBase()
            ->selectRaw('min(occurred_on) as date_from, max(occurred_on) as date_to, count(*) as transaction_count')
            ->first();

        if ((int) $coverage->transaction_count === 0) {
            return null;
        }

        return [
            'currency' => $currency->value,
            'period' => $period->data(),
            'coverage' => [
                'date_from' => (string) $coverage->date_from,
                'date_to' => (string) $coverage->date_to,
                'transaction_count' => (int) $coverage->transaction_count,
                'source' => $this->readRecordedCoverage->handle($owner, $dateFrom, $dateTo),
            ],
            'summary' => $this->readPeriodSummary->handle($owner, $currency, $dateFrom, $dateTo),
            'material_change' => $includeGuidance
                ? $this->materialCategoryChange($owner, $currency, $period)
                : null,
            'input_request' => $includeGuidance
                ? $this->inputRequest($owner, $currency, $dateFrom, $dateTo)
                : null,
        ];
    }

    /**
     * @param  list<Currency>  $currencies
     * @param  array{currency?: string, period?: string, anchor?: string, preset?: string, date_from?: string, date_to?: string}  $filters
     */
    private function reportingPeriod(User $owner, array $currencies, array $filters): ReportingPeriod
    {
        $today = CarbonImmutable::today(config('app.timezone'));

        if (Arr::hasAny($filters, ['period', 'anchor', 'preset', 'date_from', 'date_to'])) {
            return ReportingPeriod::fromFilters($filters)->endingNoLaterThan($today);
        }

        $latestOccurredOn = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereIn('currency', array_map(
                fn (Currency $currency): string => $currency->value,
                $currencies,
            ))
            ->whereNull('voided_at')
            ->max('occurred_on');

        if (! is_string($latestOccurredOn)) {
            return ReportingPeriod::fromFilters([])->endingNoLaterThan($today);
        }

        $latestDate = CarbonImmutable::parse($latestOccurredOn, config('app.timezone'));

        return ReportingPeriod::fromFilters([
            'period' => 'month',
            'anchor' => $latestDate->toDateString(),
        ])->endingNoLaterThan($today);
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

    /** @return MaterialChange|null */
    private function materialCategoryChange(
        User $owner,
        Currency $currency,
        ReportingPeriod $reportingPeriod,
    ): ?array {
        $dateFrom = $reportingPeriod->dateFrom;
        $dateTo = $reportingPeriod->dateTo;
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->get(['id', 'parent_id', 'name']);
        $categoriesById = $categories->keyBy('id');
        $comparison = EquivalentPeriods::forPeriod($reportingPeriod);
        $periods = $comparison->all();

        /** @var array<int|string, array<int, ExactInteger>> $amounts */
        $amounts = [];
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->whereBetween('occurred_on', [
                $periods[3][0]->toDateString(),
                $dateTo->toDateString(),
            ])
            ->select(['id', 'occurred_on', 'amount_minor', 'kind', 'category_id'])
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

            foreach ($this->netSpendingAllocation->byTopLevelCategory($transaction, $categoriesById) as $categoryKey => $amount) {
                $amounts[$categoryKey][$periodIndex] = ($amounts[$categoryKey][$periodIndex] ?? ExactInteger::from(0))
                    ->add($amount);
            }
        }

        $changes = [];

        foreach ($amounts as $categoryKey => $periodAmounts) {
            $current = $periodAmounts[0] ?? ExactInteger::from(0);
            $typical = $comparison->typicalAmount($periodAmounts);
            $change = $current->subtract($typical);

            if ($change->compare(ExactInteger::from(0)) === 0) {
                continue;
            }

            $category = $categoryKey === 'uncategorized'
                ? ['id' => null, 'name' => 'Uncategorized']
                : [
                    'id' => $categoriesById->get($categoryKey)->id,
                    'name' => $categoriesById->get($categoryKey)->name,
                ];
            $changes[] = [
                'category' => $category,
                'current_total_minor' => $current->value(),
                'typical_total_minor' => $typical->value(),
                'change_minor' => $change->value(),
                'comparison_periods' => array_map(
                    fn (array $period): array => $this->analysisPeriod($period[0], $period[1]),
                    $comparison->comparisons(),
                ),
            ];
        }

        usort($changes, fn (array $left, array $right): int => $this->absolute($right['change_minor'])
            ->compare($this->absolute($left['change_minor'])));

        return $changes[0] ?? null;
    }

    /** @return AnalysisPeriod */
    private function analysisPeriod(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        return [
            'label' => $dateFrom->isoFormat('MMM D').' – '.$dateTo->isoFormat('MMM D, YYYY'),
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
