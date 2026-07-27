<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ReadCategoryAssignmentProvenance;
use App\Currency;
use App\ExactInteger;
use App\Models\Category;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * @phpstan-import-type CategoryAssignmentProvenanceData from ReadCategoryAssignmentProvenance
 *
 * @phpstan-type LedgerFiltersInput array{
 *     search?: string|null,
 *     date_from?: string|null,
 *     date_to?: string|null,
 *     currency?: string|null,
 *     category_state?: string|null,
 *     review_state?: string|null,
 *     refund_relationship?: string|null,
 *     void_state?: string|null,
 *     duplicate_status?: string|null,
 *     selected?: int|string|null,
 *     inspector?: string|null
 * }
 * @phpstan-type LedgerFilters array{
 *     search: string,
 *     date_from: string|null,
 *     date_to: string|null,
 *     currency: string,
 *     category_state: string,
 *     review_state: string,
 *     refund_relationship: string,
 *     void_state: string,
 *     duplicate_status: string
 * }
 * @phpstan-type ActiveLedgerTransactionData array{
 *     id: int,
 *     occurred_on: string,
 *     amount_minor: string,
 *     currency: string,
 *     kind: string,
 *     merchant_description: string,
 *     confirmed_at: string,
 *     revision: int,
 *     original_purchase: array{id: int, merchant_description: string}|null,
 *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
 *     review_state: string,
 *     review_field_count: int,
 *     refund_relationship_review_count: int,
 *     duplicate_status: string,
 *     state_change_idempotency_key: string
 * }
 * @phpstan-type VoidedLedgerTransactionData array{
 *     id: int,
 *     occurred_on: string,
 *     amount_minor: string,
 *     currency: string,
 *     kind: string,
 *     merchant_description: string,
 *     confirmed_at: string,
 *     revision: int,
 *     original_purchase: array{id: int, merchant_description: string}|null,
 *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
 *     review_state: string,
 *     review_field_count: int,
 *     refund_relationship_review_count: int,
 *     duplicate_status: string,
 *     voided_at: string,
 *     duplicate_resolution: array{id: int, revision: int, first_transaction_revision: int, second_transaction_revision: int, reopen_idempotency_key: string}|null,
 *     state_change_idempotency_key: string
 * }
 * @phpstan-type LedgerTransactionData array{
 *     id: int,
 *     occurred_on: string,
 *     amount_minor: string,
 *     currency: string,
 *     kind: string,
 *     merchant_description: string,
 *     confirmed_at: string,
 *     revision: int,
 *     original_purchase: array{id: int, merchant_description: string}|null,
 *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
 *     review_state: string,
 *     review_field_count: int,
 *     refund_relationship_review_count: int,
 *     duplicate_status: string
 * }
 */
class ReadLedger
{
    private const TRANSACTION_COLUMNS = [
        'id',
        'occurred_on',
        'amount_minor',
        'currency',
        'kind',
        'merchant_description',
        'confirmed_at',
        'revision',
        'original_purchase_id',
        'category_id',
        'category_assignment_provenance',
        'provisional_fields',
        'refund_relationship_review_reasons',
    ];

    public function __construct(
        private ReadTransactionInspector $readTransactionInspector,
        private ReadCategoryAssignmentProvenance $readCategoryAssignmentProvenance,
    ) {}

    /**
     * @param  LedgerFiltersInput  $filters
     * @return array{
     *     today: string,
     *     totals: array{USD: string, PEN: string},
     *     category_totals: list<array{category: array{id: int|null, name: string}, totals: array{USD: string, PEN: string}}>,
     *     purchase_options: list<array{id: int, occurred_on: string, merchant_description: string, currency: string}>,
     *     transactions: list<ActiveLedgerTransactionData>,
     *     voided_transactions: list<VoidedLedgerTransactionData>,
     *     filters: LedgerFilters,
     *     selected_transaction: array<string, mixed>|null
     * }
     */
    public function handle(
        User $owner,
        array $filters = [],
        ?int $selectedTransactionId = null,
        bool $includeEveryMatch = false,
    ): array {
        $filters = $this->normalizeFilters($filters);
        $totalRows = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->toBase()
            ->select('currency')
            ->selectRaw(
                'SUM(CASE WHEN kind = ? THEN amount_minor ELSE -amount_minor END) AS total_minor',
                [TransactionKind::Purchase->value],
            )
            ->groupBy('currency')
            ->pluck('total_minor', 'currency');

        $filteredQuery = $this->applyFilters(
            Transaction::query()->whereBelongsTo($owner, 'owner'),
            $filters,
        );
        $transactionQuery = (clone $filteredQuery)
            ->whereNull('voided_at')
            ->select(self::TRANSACTION_COLUMNS)
            ->with([
                'originalPurchase:id,merchant_description',
                'category:id,name',
                'currentCategoryAssignment.owner:id,name',
                'currentCategoryAssignment.linkedPurchase:id,merchant_description',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id');

        if ($filters['void_state'] === 'voided') {
            $transactionQuery->whereRaw('false');
        }

        if (! $includeEveryMatch) {
            $transactionQuery->limit(100);
        }

        $transactionModels = $transactionQuery->get();
        $voidedTransactionQuery = (clone $filteredQuery)
            ->whereNotNull('voided_at')
            ->select([...self::TRANSACTION_COLUMNS, 'voided_at'])
            ->with([
                'originalPurchase:id,merchant_description',
                'category:id,name',
                'currentCategoryAssignment.owner:id,name',
                'currentCategoryAssignment.linkedPurchase:id,merchant_description',
            ])
            ->orderByDesc('voided_at')
            ->orderByDesc('id');

        if ($filters['void_state'] === 'active') {
            $voidedTransactionQuery->whereRaw('false');
        }

        if (! $includeEveryMatch) {
            $voidedTransactionQuery->limit(100);
        }

        $voidedTransactionModels = $voidedTransactionQuery->get();
        $visibleTransactionIds = collect($transactionModels->modelKeys())
            ->concat($voidedTransactionModels->modelKeys());
        $duplicateStatuses = [];

        if ($visibleTransactionIds->isNotEmpty()) {
            $visibleDuplicateRelationships = SuspectedDuplicate::query()
                ->whereBelongsTo($owner, 'owner')
                ->where(function ($query) use ($visibleTransactionIds): void {
                    $query
                        ->whereIn('first_transaction_id', $visibleTransactionIds)
                        ->orWhereIn('second_transaction_id', $visibleTransactionIds);
                })
                ->get(['first_transaction_id', 'second_transaction_id', 'resolved_at']);

            foreach ($visibleDuplicateRelationships as $relationship) {
                $status = $relationship->resolved_at === null ? 'suspected' : 'resolved';

                foreach ([$relationship->first_transaction_id, $relationship->second_transaction_id] as $transactionId) {
                    if ($status === 'suspected' || ! isset($duplicateStatuses[$transactionId])) {
                        $duplicateStatuses[$transactionId] = $status;
                    }
                }
            }
        }

        $transactions = [];

        foreach ($transactionModels as $transaction) {
            $transactions[] = [
                ...$this->transactionData(
                    $transaction,
                    $owner,
                    $duplicateStatuses[$transaction->id] ?? 'none',
                ),
                'state_change_idempotency_key' => (string) Str::uuid(),
            ];
        }

        $duplicateResolutionsByVoidedTransaction = SuspectedDuplicate::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereIn('voided_transaction_id', $voidedTransactionModels->modelKeys())
            ->whereNotNull('resolved_at')
            ->with([
                'firstTransaction:id,revision',
                'secondTransaction:id,revision',
            ])
            ->get([
                'id',
                'first_transaction_id',
                'second_transaction_id',
                'revision',
                'voided_transaction_id',
            ])
            ->keyBy('voided_transaction_id');

        $voidedTransactions = [];

        foreach ($voidedTransactionModels as $transaction) {
            assert($transaction->voided_at !== null);
            $duplicateResolution = $duplicateResolutionsByVoidedTransaction->get(
                $transaction->id,
            );

            $voidedTransactions[] = [
                ...$this->transactionData(
                    $transaction,
                    $owner,
                    $duplicateStatuses[$transaction->id] ?? 'none',
                ),
                'voided_at' => $transaction->voided_at->toIso8601String(),
                'duplicate_resolution' => $duplicateResolution === null
                    ? null
                    : [
                        'id' => $duplicateResolution->id,
                        'revision' => $duplicateResolution->revision,
                        'first_transaction_revision' => $duplicateResolution->firstTransaction->revision,
                        'second_transaction_revision' => $duplicateResolution->secondTransaction->revision,
                        'reopen_idempotency_key' => (string) Str::uuid(),
                    ],
                'state_change_idempotency_key' => (string) Str::uuid(),
            ];
        }

        $categoryTotalRows = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereNotNull('category_id')
            ->toBase()
            ->select(['category_id', 'currency'])
            ->selectRaw(
                'SUM(CASE WHEN kind = ? THEN amount_minor ELSE -amount_minor END) AS total_minor',
                [TransactionKind::Purchase->value],
            )
            ->groupBy(['category_id', 'currency'])
            ->get();

        $categories = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);
        $categoryTotalsById = [];

        foreach ($categoryTotalRows as $categoryTotalRow) {
            $categoryTotalsById[(int) $categoryTotalRow->category_id][(string) $categoryTotalRow->currency] = ExactInteger::from(
                (string) $categoryTotalRow->total_minor,
            );
        }

        foreach ($categories as $category) {
            if (
                $category->parent_id === null
                || ! isset($categoryTotalsById[$category->id])
            ) {
                continue;
            }

            foreach (Currency::cases() as $currency) {
                $childTotal = $categoryTotalsById[$category->id][$currency->value]
                    ?? ExactInteger::from(0);
                $parentTotal = $categoryTotalsById[$category->parent_id][$currency->value]
                    ?? ExactInteger::from(0);
                $categoryTotalsById[$category->parent_id][$currency->value] = $parentTotal->add($childTotal);
            }
        }

        $categoryTotals = [];

        foreach ($categories as $category) {
            if (! isset($categoryTotalsById[$category->id])) {
                continue;
            }

            $categoryTotals[] = [
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                ],
                'totals' => [
                    Currency::Usd->value => (
                        $categoryTotalsById[$category->id][Currency::Usd->value]
                        ?? ExactInteger::from(0)
                    )->value(),
                    Currency::Pen->value => (
                        $categoryTotalsById[$category->id][Currency::Pen->value]
                        ?? ExactInteger::from(0)
                    )->value(),
                ],
            ];
        }

        $uncategorizedTotalRows = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereNull('category_id')
            ->toBase()
            ->select('currency')
            ->selectRaw(
                'SUM(CASE WHEN kind = ? THEN amount_minor ELSE -amount_minor END) AS total_minor',
                [TransactionKind::Purchase->value],
            )
            ->groupBy('currency')
            ->pluck('total_minor', 'currency');

        if ($uncategorizedTotalRows->isNotEmpty()) {
            $categoryTotals[] = [
                'category' => [
                    'id' => null,
                    'name' => 'Uncategorized',
                ],
                'totals' => [
                    Currency::Usd->value => (string) $uncategorizedTotalRows->get(Currency::Usd->value, '0'),
                    Currency::Pen->value => (string) $uncategorizedTotalRows->get(Currency::Pen->value, '0'),
                ],
            ];
        }

        $purchaseModels = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->where('kind', TransactionKind::Purchase)
            ->select([
                'id',
                'occurred_on',
                'merchant_description',
                'currency',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
        $purchaseOptions = [];

        foreach ($purchaseModels as $purchase) {
            $purchaseOptions[] = [
                'id' => $purchase->id,
                'occurred_on' => $purchase->occurred_on->toDateString(),
                'merchant_description' => $purchase->merchant_description,
                'currency' => $purchase->currency->value,
            ];
        }

        return [
            'today' => now(config('app.timezone'))->toDateString(),
            'totals' => [
                Currency::Usd->value => (string) $totalRows->get(Currency::Usd->value, '0'),
                Currency::Pen->value => (string) $totalRows->get(Currency::Pen->value, '0'),
            ],
            'category_totals' => $categoryTotals,
            'purchase_options' => $purchaseOptions,
            'transactions' => $transactions,
            'voided_transactions' => $voidedTransactions,
            'filters' => $filters,
            'selected_transaction' => $this->readTransactionInspector->handle(
                $owner,
                $selectedTransactionId,
            ),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     confirmed_at: string,
     *     revision: int,
     *     original_purchase: array{id: int, merchant_description: string}|null,
     *     category: array{id: int, name: string, provenance: CategoryAssignmentProvenanceData}|null,
     *     review_state: string,
     *     review_field_count: int,
     *     refund_relationship_review_count: int,
     *     duplicate_status: string
     * }
     */
    private function transactionData(Transaction $transaction, User $owner, string $duplicateStatus): array
    {
        $category = null;

        if ($transaction->category !== null) {
            $provenance = $this->readCategoryAssignmentProvenance->handle($transaction, $owner);
            assert($provenance !== null);
            $category = [
                'id' => $transaction->category->id,
                'name' => $transaction->category->name,
                'provenance' => $provenance,
            ];
        }

        return [
            'id' => $transaction->id,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value,
            'kind' => $transaction->kind->value,
            'merchant_description' => $transaction->merchant_description,
            'confirmed_at' => $transaction->confirmed_at->toIso8601String(),
            'revision' => $transaction->revision,
            'original_purchase' => $transaction->originalPurchase === null
                ? null
                : [
                    'id' => $transaction->originalPurchase->id,
                    'merchant_description' => $transaction->originalPurchase->merchant_description,
                ],
            'category' => $category,
            'review_state' => $transaction->category_id === null
                || $transaction->provisional_fields !== []
                || $transaction->refund_relationship_review_reasons !== []
                || $duplicateStatus === 'suspected'
                    ? 'outstanding'
                    : 'clear',
            'review_field_count' => count($transaction->provisional_fields),
            'refund_relationship_review_count' => count($transaction->refund_relationship_review_reasons),
            'duplicate_status' => $duplicateStatus,
        ];
    }

    /**
     * @param  LedgerFiltersInput  $filters
     * @return LedgerFilters
     */
    private function normalizeFilters(array $filters): array
    {
        return [
            'search' => Str::squish((string) ($filters['search'] ?? '')),
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'currency' => $filters['currency'] ?? 'all',
            'category_state' => $filters['category_state'] ?? 'all',
            'review_state' => $filters['review_state'] ?? 'all',
            'refund_relationship' => $filters['refund_relationship'] ?? 'all',
            'void_state' => $filters['void_state'] ?? 'all',
            'duplicate_status' => $filters['duplicate_status'] ?? 'all',
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  LedgerFilters  $filters
     * @return Builder<Transaction>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $query->where('merchant_description', 'ilike', '%'.$filters['search'].'%');
        }

        $query
            ->when($filters['date_from'] !== null, fn (Builder $query) => $query->whereDate('occurred_on', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== null, fn (Builder $query) => $query->whereDate('occurred_on', '<=', $filters['date_to']))
            ->when($filters['currency'] !== 'all', fn (Builder $query) => $query->where('currency', $filters['currency']))
            ->when($filters['category_state'] === 'categorized', fn (Builder $query) => $query->whereNotNull('category_id'))
            ->when($filters['category_state'] === 'uncategorized', fn (Builder $query) => $query->whereNull('category_id'))
            ->when($filters['refund_relationship'] === 'linked', fn (Builder $query) => $query
                ->where('kind', TransactionKind::Refund)
                ->whereNotNull('original_purchase_id'))
            ->when($filters['refund_relationship'] === 'unlinked', fn (Builder $query) => $query
                ->where('kind', TransactionKind::Refund)
                ->whereNull('original_purchase_id'))
            ->when($filters['refund_relationship'] === 'not_applicable', fn (Builder $query) => $query
                ->where('kind', TransactionKind::Purchase));

        if ($filters['review_state'] === 'outstanding') {
            $query->where(function (Builder $query): void {
                $query
                    ->whereNull('category_id')
                    ->orWhereJsonLength('provisional_fields', '>', 0)
                    ->orWhereJsonLength('refund_relationship_review_reasons', '>', 0)
                    ->orWhere(fn (Builder $query) => $this->whereHasDuplicateRelationship($query, false));
            });
        } elseif ($filters['review_state'] === 'clear') {
            $query
                ->whereNotNull('category_id')
                ->whereJsonLength('provisional_fields', 0)
                ->whereJsonLength('refund_relationship_review_reasons', 0);
            $this->whereHasNoDuplicateRelationship($query, false);
        }

        if ($filters['duplicate_status'] === 'suspected') {
            $this->whereHasDuplicateRelationship($query, false);
        } elseif ($filters['duplicate_status'] === 'resolved') {
            $this->whereHasDuplicateRelationship($query, true);
            $this->whereHasNoDuplicateRelationship($query, false);
        } elseif ($filters['duplicate_status'] === 'none') {
            $this->whereHasNoDuplicateRelationship($query);
        }

        return $query;
    }

    /** @param Builder<Transaction> $query */
    private function whereHasDuplicateRelationship(Builder $query, ?bool $resolved = null): void
    {
        $query->whereExists(function ($query) use ($resolved): void {
            $query
                ->selectRaw('1')
                ->from('suspected_duplicates')
                ->whereColumn('suspected_duplicates.user_id', 'transactions.user_id')
                ->where(function ($query): void {
                    $query
                        ->whereColumn('suspected_duplicates.first_transaction_id', 'transactions.id')
                        ->orWhereColumn('suspected_duplicates.second_transaction_id', 'transactions.id');
                })
                ->when($resolved === true, fn ($query) => $query->whereNotNull('suspected_duplicates.resolved_at'))
                ->when($resolved === false, fn ($query) => $query->whereNull('suspected_duplicates.resolved_at'));
        });
    }

    /** @param Builder<Transaction> $query */
    private function whereHasNoDuplicateRelationship(Builder $query, ?bool $resolved = null): void
    {
        $query->whereNotExists(function ($query) use ($resolved): void {
            $query
                ->selectRaw('1')
                ->from('suspected_duplicates')
                ->whereColumn('suspected_duplicates.user_id', 'transactions.user_id')
                ->where(function ($query): void {
                    $query
                        ->whereColumn('suspected_duplicates.first_transaction_id', 'transactions.id')
                        ->orWhereColumn('suspected_duplicates.second_transaction_id', 'transactions.id');
                })
                ->when($resolved === true, fn ($query) => $query->whereNotNull('suspected_duplicates.resolved_at'))
                ->when($resolved === false, fn ($query) => $query->whereNull('suspected_duplicates.resolved_at'));
        });
    }
}
