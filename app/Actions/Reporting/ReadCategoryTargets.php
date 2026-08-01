<?php

namespace App\Actions\Reporting;

use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\CategoryTargetRevision;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type CategoryTargetProgressData from CalculateCategoryTargetProgress
 *
 * @phpstan-type CategoryTargetData array{
 *     id: int,
 *     revision: int,
 *     category: array{id: int, name: string},
 *     currency: 'USD'|'PEN',
 *     starts_on: string,
 *     effective_month: string|null,
 *     status: 'active'|'scheduled'|'retired',
 *     amount_minor: string|null,
 *     progress: CategoryTargetProgressData|null
 * }
 */
final class ReadCategoryTargets
{
    public function __construct(
        private ReadSpendingSummary $readSpendingSummary,
        private CalculateCategoryTargetProgress $calculateCategoryTargetProgress,
    ) {}

    /**
     * @param  array{
     *     period: array{date_to: string, is_complete: bool},
     *     baseline: array{average: array{
     *         combined_total: array{currency: string|null},
     *         category_totals: list<array{category: array{id: int|null, name: string}, combined_total: array{amount_minor: string|null}}>
     *     }|null}
     * }  $insights
     * @return array{target_defaults: array{effective_month: string}, target_options: list<array{category: array{id: int, name: string}, baseline_prefill: array{currency: string, amount_minor: string}|null}>, category_targets: list<CategoryTargetData>}
     */
    public function handle(User $owner, CarbonImmutable $selectedMonth, array $insights): array
    {
        $targetedCategoryIds = CategoryTarget::query()
            ->whereBelongsTo($owner, 'owner')
            ->pluck('category_id')
            ->all();
        $baselineAverage = $insights['baseline']['average'];
        $baselineCurrency = $baselineAverage['combined_total']['currency'] ?? null;
        $baselineByCategoryId = collect($baselineAverage['category_totals'] ?? [])
            ->keyBy(fn (array $total): int|string => $total['category']['id'] ?? 'uncategorized');

        $options = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('retired_at')
            ->whereNotIn('id', $targetedCategoryIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Category $category) use ($baselineByCategoryId, $baselineCurrency): array {
                $categoryBaseline = $baselineByCategoryId->get($category->id);
                $amountMinor = data_get($categoryBaseline, 'combined_total.amount_minor');

                return [
                    'category' => [
                        'id' => $category->id,
                        'name' => $category->name,
                    ],
                    'baseline_prefill' => is_string($baselineCurrency) && is_string($amountMinor)
                        ? [
                            'currency' => $baselineCurrency,
                            'amount_minor' => $amountMinor,
                        ]
                        : null,
                ];
            })
            ->values()
            ->all();
        $options = array_values($options);

        $summariesByCurrency = [];
        $targetModels = CategoryTarget::query()
            ->whereBelongsTo($owner, 'owner')
            ->with('category:id,name')
            ->select('category_targets.*')
            ->addSelect([
                'applicable_revision_id' => CategoryTargetRevision::query()
                    ->select('id')
                    ->whereColumn('category_target_id', 'category_targets.id')
                    ->whereRaw(
                        'effective_month <= GREATEST(category_targets.starts_on, CAST(? AS date))',
                        [$selectedMonth->toDateString()],
                    )
                    ->orderByDesc('effective_month')
                    ->orderByDesc('revision')
                    ->limit(1),
            ])
            ->join('categories', 'categories.id', '=', 'category_targets.category_id')
            ->orderBy('categories.name')
            ->get();
        $applicableRevisions = CategoryTargetRevision::query()
            ->whereKey($targetModels->pluck('applicable_revision_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $targets = $targetModels
            ->map(function (CategoryTarget $target) use ($owner, $selectedMonth, $insights, $applicableRevisions, &$summariesByCurrency): array {
                $applicableRevision = $target->applicable_revision_id === null
                    ? null
                    : $applicableRevisions->get($target->applicable_revision_id);

                if ($applicableRevision === null || $applicableRevision->amount_minor === null) {
                    return [
                        'id' => $target->id,
                        'revision' => $target->revision,
                        'category' => ['id' => $target->category->id, 'name' => $target->category->name],
                        'currency' => $target->currency->value,
                        'starts_on' => $target->starts_on->toDateString(),
                        'effective_month' => $applicableRevision?->effective_month->toDateString(),
                        'status' => 'retired',
                        'amount_minor' => null,
                        'progress' => null,
                    ];
                }

                if ($target->starts_on->greaterThan($selectedMonth)) {
                    return [
                        'id' => $target->id,
                        'revision' => $target->revision,
                        'category' => ['id' => $target->category->id, 'name' => $target->category->name],
                        'currency' => $target->currency->value,
                        'starts_on' => $target->starts_on->toDateString(),
                        'effective_month' => $applicableRevision->effective_month->toDateString(),
                        'status' => 'scheduled',
                        'amount_minor' => (string) $applicableRevision->amount_minor,
                        'progress' => null,
                    ];
                }

                $currency = $target->currency->value;
                $summariesByCurrency[$currency] ??= $this->readSpendingSummary->handle(
                    owner: $owner,
                    dateFrom: $selectedMonth,
                    dateTo: CarbonImmutable::parse($insights['period']['date_to']),
                    reportingCurrency: $target->currency,
                );
                $summary = $summariesByCurrency[$currency];
                $categoryTotal = collect($summary['category_totals'])
                    ->firstWhere('category.id', $target->category_id);
                $combined = $categoryTotal['combined_total'] ?? [
                    'amount_minor' => '0',
                    'unavailable_reason' => null,
                    'missing_rate_dates' => [],
                ];

                return [
                    'id' => $target->id,
                    'revision' => $target->revision,
                    'category' => ['id' => $target->category->id, 'name' => $target->category->name],
                    'currency' => $currency,
                    'starts_on' => $target->starts_on->toDateString(),
                    'effective_month' => $applicableRevision->effective_month->toDateString(),
                    'status' => 'active',
                    'amount_minor' => (string) $applicableRevision->amount_minor,
                    'progress' => $this->calculateCategoryTargetProgress->handle(
                        targetAmountMinor: (string) $applicableRevision->amount_minor,
                        spentMinor: $combined['amount_minor'],
                        isComplete: $insights['period']['is_complete'],
                        unavailableReason: $combined['unavailable_reason'] === 'missing_exchange_rates'
                            ? 'missing_exchange_rates'
                            : null,
                        missingRateDates: $combined['missing_rate_dates'],
                    ),
                ];
            })
            ->values()
            ->all();
        $targets = array_values($targets);

        return [
            'target_defaults' => [
                'effective_month' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            ],
            'target_options' => $options,
            'category_targets' => $targets,
        ];
    }
}
