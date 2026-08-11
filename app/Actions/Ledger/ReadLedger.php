<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ReadCategoryAssignmentProvenance;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
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
 *     kind?: string|null,
 *     category_id?: int|string|null,
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
 *     kind: string,
 *     category_id: int|null,
 *     category_state: string,
 *     review_state: string,
 *     refund_relationship: string,
 *     void_state: string,
 *     duplicate_status: string
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
 *     fields: list<array{name: string, label: string, value: string}>,
 *     refund_relationship_review_count: int,
 *     duplicate_status: string,
 *     voided_at: string|null,
 *     duplicate_resolution: array{id: int, revision: int, first_transaction_revision: int, second_transaction_revision: int, reopen_idempotency_key: string}|null,
 *     state_change_idempotency_key: string
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

    public function __construct(private ReadCategoryAssignmentProvenance $readCategoryAssignmentProvenance) {}

    /**
     * @param  LedgerFiltersInput  $filters
     * @return array{
     *     today: string,
     *     transactions: list<LedgerTransactionData>,
     *     voided_transactions: list<LedgerTransactionData>,
     *     pagination: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, previous_page_url: string|null, next_page_url: string|null},
     *     filters: LedgerFilters
     * }
     */
    public function handle(
        User $owner,
        array $filters = [],
    ): array {
        $filters = $this->normalizeFilters($filters);
        $transactionQuery = $this->applyFilters(
            Transaction::query()->whereBelongsTo($owner, 'owner'),
            $filters,
        )
            ->when($filters['void_state'] === 'active', fn (Builder $query) => $query->whereNull('voided_at'))
            ->when($filters['void_state'] === 'voided', fn (Builder $query) => $query->whereNotNull('voided_at'))
            ->select([...self::TRANSACTION_COLUMNS, 'voided_at'])
            ->with([
                'originalPurchase:id,merchant_description',
                'category:id,name',
                'currentCategoryAssignment.owner:id,name',
                'currentCategoryAssignment.linkedPurchase:id,merchant_description',
                'receiptBreakdown.lineItems:id,receipt_breakdown_id,category_id',
            ])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id');
        $transactions = $transactionQuery->paginate(25)->withQueryString();
        $transactionModels = collect($transactions->items());
        $visibleTransactionIds = $transactionModels
            ->map(fn (Transaction $transaction): int => $transaction->id)
            ->all();
        $duplicateStatuses = [];

        if ($visibleTransactionIds !== []) {
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

        $duplicateResolutionsByVoidedTransaction = SuspectedDuplicate::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereIn('voided_transaction_id', $visibleTransactionIds)
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

        $ledgerRows = $transactionModels->map(function (Transaction $transaction) use ($owner, $duplicateStatuses, $duplicateResolutionsByVoidedTransaction): array {
            $duplicateResolution = $duplicateResolutionsByVoidedTransaction->get(
                $transaction->id,
            );

            return [
                ...$this->transactionData(
                    $transaction,
                    $owner,
                    $duplicateStatuses[$transaction->id] ?? 'none',
                ),
                'voided_at' => $transaction->voided_at?->toIso8601String(),
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
        });

        return [
            'today' => now(config('app.timezone'))->toDateString(),
            'transactions' => array_values($ledgerRows
                ->whereNull('voided_at')
                ->all()),
            'voided_transactions' => array_values($ledgerRows
                ->whereNotNull('voided_at')
                ->all()),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'from' => $transactions->firstItem(),
                'to' => $transactions->lastItem(),
                'previous_page_url' => $transactions->previousPageUrl(),
                'next_page_url' => $transactions->nextPageUrl(),
            ],
            'filters' => $filters,
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
     *     fields: list<array{name: string, label: string, value: string}>,
     *     refund_relationship_review_count: int,
     *     duplicate_status: string
     * }
     */
    private function transactionData(Transaction $transaction, User $owner, string $duplicateStatus): array
    {
        $category = null;
        $receiptBreakdown = $transaction->receiptBreakdown?->lineItems->isNotEmpty() === true
            ? $transaction->receiptBreakdown
            : null;
        $unresolvedCategoryCount = $receiptBreakdown === null
            ? ($transaction->category_id === null ? 1 : 0)
            : $receiptBreakdown->lineItems->whereNull('category_id')->count();
        $reviewFields = [];

        foreach ($transaction->provisional_fields as $fieldName) {
            $field = ReviewableTransactionField::from($fieldName);
            $reviewFields[] = [
                'name' => $field->value,
                'label' => $field->label(),
                'value' => $field->valueFor($transaction),
            ];
        }

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
            'review_state' => $unresolvedCategoryCount > 0
                || $transaction->provisional_fields !== []
                || $transaction->refund_relationship_review_reasons !== []
                || $duplicateStatus === 'suspected'
                    ? 'outstanding'
                    : 'clear',
            'review_field_count' => count($transaction->provisional_fields) + $unresolvedCategoryCount,
            'fields' => $reviewFields,
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
            'kind' => $filters['kind'] ?? 'all',
            'category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
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
            ->when($filters['kind'] !== 'all', fn (Builder $query) => $query->where('kind', $filters['kind']))
            ->when($filters['category_id'] !== null, fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when($filters['refund_relationship'] === 'linked', fn (Builder $query) => $query
                ->where('kind', TransactionKind::Refund)
                ->whereNotNull('original_purchase_id'))
            ->when($filters['refund_relationship'] === 'unlinked', fn (Builder $query) => $query
                ->where('kind', TransactionKind::Refund)
                ->whereNull('original_purchase_id'))
            ->when($filters['refund_relationship'] === 'not_applicable', fn (Builder $query) => $query
                ->where('kind', TransactionKind::Purchase));

        if ($filters['category_state'] === 'categorized') {
            $this->whereHasNoUncategorizedContribution($query);
        } elseif ($filters['category_state'] === 'uncategorized') {
            $this->whereHasUncategorizedContribution($query);
        }

        if ($filters['review_state'] === 'outstanding') {
            $query->whereRequiresReview();
        } elseif ($filters['review_state'] === 'clear') {
            $query
                ->whereJsonLength('provisional_fields', 0)
                ->whereJsonLength('refund_relationship_review_reasons', 0);
            $this->whereHasNoUncategorizedContribution($query);
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

    /** @param Builder<Transaction> $query */
    private function whereHasUncategorizedContribution(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('category_id')
                        ->whereDoesntHave('receiptBreakdown', fn (Builder $query) => $query
                            ->whereHas('lineItems'));
                })
                ->orWhereHas('receiptBreakdown', fn (Builder $query) => $query
                    ->whereHas('lineItems', fn (Builder $query) => $query->whereNull('category_id')));
        });
    }

    /** @param Builder<Transaction> $query */
    private function whereHasNoUncategorizedContribution(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query
                        ->whereNotNull('category_id')
                        ->whereDoesntHave('receiptBreakdown', fn (Builder $query) => $query
                            ->whereHas('lineItems'));
                })
                ->orWhereHas('receiptBreakdown', fn (Builder $query) => $query
                    ->whereHas('lineItems')
                    ->whereDoesntHave('lineItems', fn (Builder $query) => $query->whereNull('category_id')));
        });
    }
}
