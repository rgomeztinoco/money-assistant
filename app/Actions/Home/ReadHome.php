<?php

namespace App\Actions\Home;

use App\Actions\Reporting\EquivalentMonthPeriods;
use App\Actions\Reporting\NetSpendingAllocation;
use App\Actions\Reporting\ReadPeriodSummary;
use App\Currency;
use App\ExactInteger;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;

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
    ) {}

    /** @return array{primary: Briefing|null, secondary: Briefing|null} */
    public function handle(User $owner): array
    {
        return [
            'primary' => $this->briefing($owner, Currency::Pen, true),
            'secondary' => $this->briefing($owner, Currency::Usd, false),
        ];
    }

    /** @return Briefing|null */
    private function briefing(User $owner, Currency $currency, bool $includeGuidance): ?array
    {
        $period = $this->meaningfulPeriod($owner, $currency);

        if ($period === null) {
            return null;
        }

        [$dateFrom, $dateTo] = $period;
        $coverageQuery = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()]);
        $coverage = $coverageQuery
            ->toBase()
            ->selectRaw('min(occurred_on) as date_from, max(occurred_on) as date_to, count(*) as transaction_count')
            ->first();

        return [
            'currency' => $currency->value,
            'period' => [
                'label' => $dateFrom->isoFormat('MMMM YYYY'),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'coverage' => [
                'date_from' => (string) $coverage->date_from,
                'date_to' => (string) $coverage->date_to,
                'transaction_count' => (int) $coverage->transaction_count,
            ],
            'summary' => $this->readPeriodSummary->handle($owner, $currency, $dateFrom, $dateTo),
            'material_change' => $includeGuidance
                ? $this->materialCategoryChange($owner, $currency, $dateFrom, $dateTo)
                : null,
            'input_request' => $includeGuidance
                ? $this->inputRequest($owner, $currency, $dateFrom, $dateTo)
                : null,
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function meaningfulPeriod(User $owner, Currency $currency): ?array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $latestOccurredOn = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('currency', $currency)
            ->whereNull('voided_at')
            ->max('occurred_on');

        if (! is_string($latestOccurredOn)) {
            return null;
        }

        $latestDate = CarbonImmutable::parse($latestOccurredOn, config('app.timezone'));

        if ($latestDate->isSameMonth($today)) {
            return [$today->startOfMonth(), $today];
        }

        return [$latestDate->startOfMonth(), $latestDate->endOfMonth()];
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
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): ?array {
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->get(['id', 'parent_id', 'name']);
        $categoriesById = $categories->keyBy('id');
        $comparison = EquivalentMonthPeriods::forRange($dateFrom, $dateTo);
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
