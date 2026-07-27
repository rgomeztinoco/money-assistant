<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\ExactInteger;
use App\Models\Category;
use App\Models\DailyExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;

/**
 * @phpstan-type CombinedTotalData array{currency: string|null, amount_minor: string|null, unavailable_reason: 'reporting_currency_not_selected'|'missing_exchange_rates'|null, missing_rate_dates: list<string>}
 * @phpstan-type CombinedAccumulator array{amount: ExactInteger, missing_rate_dates: array<string, true>}
 * @phpstan-type SummaryAccumulator array{totals: array{USD: ExactInteger, PEN: ExactInteger}, combined: CombinedAccumulator}
 * @phpstan-type CategoryTotalData array{category: array{id: int|null, name: string}, totals: array{USD: string, PEN: string}, combined_total: CombinedTotalData}
 */
final class ReadSpendingSummary
{
    public function __construct(private ConvertTransactionAmount $convertTransactionAmount) {}

    /**
     * @return array{totals: array{USD: string, PEN: string}, combined_total: CombinedTotalData, category_totals: list<CategoryTotalData>}
     */
    public function handle(User $owner): array
    {
        $reportingCurrency = $owner->reporting_currency;
        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);
        $categoriesById = $categories->keyBy('id');
        $ratesByDate = [];

        if ($reportingCurrency !== null) {
            $requiredDates = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereNull('voided_at')
                ->where('currency', '!=', $reportingCurrency)
                ->select('occurred_on')
                ->distinct();

            foreach (DailyExchangeRate::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereIn('applicable_on', $requiredDates)
                ->cursor() as $rate) {
                $ratesByDate[$rate->applicable_on->toDateString()] = $rate;
            }
        }

        $overall = $this->emptyAccumulator();

        /** @var array<int|string, SummaryAccumulator> $categoryAccumulators */
        $categoryAccumulators = [];

        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->select(['occurred_on', 'amount_minor', 'currency', 'kind', 'category_id'])
            ->cursor();

        foreach ($transactions as $transaction) {
            $categoryKeys = [];

            if ($transaction->category_id === null) {
                $categoryKeys[] = 'uncategorized';
            } else {
                $categoryKeys[] = $transaction->category_id;
                $category = $categoriesById->get($transaction->category_id);

                if ($category?->parent_id !== null) {
                    $categoryKeys[] = $category->parent_id;
                }
            }

            $originalContribution = ExactInteger::from((string) $transaction->amount_minor);

            if ($transaction->kind === TransactionKind::Refund) {
                $originalContribution = ExactInteger::from(0)->subtract($originalContribution);
            }

            $overall = $this->addOriginalContribution(
                $overall,
                $transaction->currency,
                $originalContribution,
            );

            foreach ($categoryKeys as $categoryKey) {
                $categoryAccumulators[$categoryKey] = $this->addOriginalContribution(
                    $categoryAccumulators[$categoryKey] ?? $this->emptyAccumulator(),
                    $transaction->currency,
                    $originalContribution,
                );
            }

            if ($reportingCurrency === null) {
                continue;
            }

            $applicableOn = $transaction->occurred_on->toDateString();
            $rate = $transaction->currency === $reportingCurrency
                ? null
                : ($ratesByDate[$applicableOn] ?? null);

            if ($transaction->currency !== $reportingCurrency && $rate === null) {
                $overall = $this->addMissingRateDate($overall, $applicableOn);

                foreach ($categoryKeys as $categoryKey) {
                    $categoryAccumulators[$categoryKey] = $this->addMissingRateDate(
                        $categoryAccumulators[$categoryKey],
                        $applicableOn,
                    );
                }

                continue;
            }

            $convertedAmount = $transaction->currency === $reportingCurrency
                ? (string) $transaction->amount_minor
                : $this->convertTransactionAmount->handle(
                    amountMinor: (string) $transaction->amount_minor,
                    from: $transaction->currency,
                    to: $reportingCurrency,
                    penPerUsdScaled: (string) $rate->pen_per_usd_scaled,
                );
            $combinedContribution = ExactInteger::from($convertedAmount);

            if ($transaction->kind === TransactionKind::Refund) {
                $combinedContribution = ExactInteger::from(0)->subtract($combinedContribution);
            }

            $overall = $this->addCombinedContribution($overall, $combinedContribution);

            foreach ($categoryKeys as $categoryKey) {
                $categoryAccumulators[$categoryKey] = $this->addCombinedContribution(
                    $categoryAccumulators[$categoryKey],
                    $combinedContribution,
                );
            }
        }

        $categoryTotals = [];

        foreach ($categories as $category) {
            if (! isset($categoryAccumulators[$category->id])) {
                continue;
            }

            $categoryTotals[] = $this->categoryTotal(
                $category->id,
                $category->name,
                $categoryAccumulators[$category->id],
                $reportingCurrency,
            );
        }

        if (isset($categoryAccumulators['uncategorized'])) {
            $categoryTotals[] = $this->categoryTotal(
                null,
                'Uncategorized',
                $categoryAccumulators['uncategorized'],
                $reportingCurrency,
            );
        }

        return [
            'totals' => [
                Currency::Usd->value => $overall['totals'][Currency::Usd->value]->value(),
                Currency::Pen->value => $overall['totals'][Currency::Pen->value]->value(),
            ],
            'combined_total' => $this->combinedTotal($overall['combined'], $reportingCurrency),
            'category_totals' => $categoryTotals,
        ];
    }

    /** @return SummaryAccumulator */
    private function emptyAccumulator(): array
    {
        return [
            'totals' => [
                Currency::Usd->value => ExactInteger::from(0),
                Currency::Pen->value => ExactInteger::from(0),
            ],
            'combined' => [
                'amount' => ExactInteger::from(0),
                'missing_rate_dates' => [],
            ],
        ];
    }

    /**
     * @param  SummaryAccumulator  $accumulator
     * @return SummaryAccumulator
     */
    private function addOriginalContribution(
        array $accumulator,
        Currency $currency,
        ExactInteger $contribution,
    ): array {
        if ($currency === Currency::Usd) {
            $accumulator['totals']['USD'] = $accumulator['totals']['USD']->add($contribution);
        } else {
            $accumulator['totals']['PEN'] = $accumulator['totals']['PEN']->add($contribution);
        }

        return $accumulator;
    }

    /**
     * @param  SummaryAccumulator  $accumulator
     * @return SummaryAccumulator
     */
    private function addMissingRateDate(array $accumulator, string $applicableOn): array
    {
        $accumulator['combined']['missing_rate_dates'][$applicableOn] = true;

        return $accumulator;
    }

    /**
     * @param  SummaryAccumulator  $accumulator
     * @return SummaryAccumulator
     */
    private function addCombinedContribution(array $accumulator, ExactInteger $contribution): array
    {
        $accumulator['combined']['amount'] = $accumulator['combined']['amount']->add($contribution);

        return $accumulator;
    }

    /**
     * @param  SummaryAccumulator  $accumulator
     * @return CategoryTotalData
     */
    private function categoryTotal(
        ?int $categoryId,
        string $categoryName,
        array $accumulator,
        ?Currency $reportingCurrency,
    ): array {
        return [
            'category' => ['id' => $categoryId, 'name' => $categoryName],
            'totals' => [
                Currency::Usd->value => $accumulator['totals'][Currency::Usd->value]->value(),
                Currency::Pen->value => $accumulator['totals'][Currency::Pen->value]->value(),
            ],
            'combined_total' => $this->combinedTotal($accumulator['combined'], $reportingCurrency),
        ];
    }

    /**
     * @param  CombinedAccumulator  $accumulator
     * @return CombinedTotalData
     */
    private function combinedTotal(array $accumulator, ?Currency $reportingCurrency): array
    {
        if ($reportingCurrency === null) {
            return [
                'currency' => null,
                'amount_minor' => null,
                'unavailable_reason' => 'reporting_currency_not_selected',
                'missing_rate_dates' => [],
            ];
        }

        $missingRateDates = array_keys($accumulator['missing_rate_dates']);
        sort($missingRateDates);

        return [
            'currency' => $reportingCurrency->value,
            'amount_minor' => $missingRateDates === [] ? $accumulator['amount']->value() : null,
            'unavailable_reason' => $missingRateDates === [] ? null : 'missing_exchange_rates',
            'missing_rate_dates' => $missingRateDates,
        ];
    }
}
