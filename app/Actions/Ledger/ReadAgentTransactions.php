<?php

namespace App\Actions\Ledger;

use App\Actions\ReceiptReconciliation\ReadReceiptBreakdownState;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * @phpstan-import-type LedgerFiltersInput from ReadLedger
 *
 * @phpstan-type AgentFilters array{
 *     search?: string|null, date_from?: string|null, date_to?: string|null,
 *     currency?: string|null, kind?: string|null, category_id?: int|string|null,
 *     void_state?: string|null, updated_since?: string|null, cursor?: string|null
 * }
 * @phpstan-type AgentCategory array{id: int, name: string, parent_id: int|null, archived_at: string|null}
 * @phpstan-type RelatedTransaction array{
 *     id: int, occurred_on: string, description: string, amount_minor: string,
 *     currency: string, kind: string, direction: string, voided_at: string|null
 * }
 * @phpstan-type AgentTransaction array{
 *     id: int, occurred_on: string, amount_minor: string, currency: string, kind: string,
 *     direction: string, income_source: string|null, transfer_purpose: string|null,
 *     description: string, confirmed_at: string, review_state: string,
 *     updated_at: string|null, voided_at: string|null, category: AgentCategory|null,
 *     category_assignment_provenance: string|null, original_spending: RelatedTransaction|null,
 *     linked_refunds: list<RelatedTransaction>,
 *     category_allocations: list<array{category: AgentCategory|null, amount_minor: string}>
 * }
 * @phpstan-type TransactionPage array{
 *     transactions: list<AgentTransaction>, order: 'updated_at_asc_id_asc'|'occurred_on_desc_id_desc',
 *     as_of: string, next_cursor: string|null, checkpoint: string|null
 * }
 */
class ReadAgentTransactions
{
    public function __construct(private ReadLedger $ledger, private ReadReceiptBreakdownState $breakdown) {}

    /**
     * @param  AgentFilters  $filters
     * @return TransactionPage
     */
    public function listing(User $owner, array $filters = []): array
    {
        $asOf = now()->utc();

        if (! isset($filters['cursor'])) {
            /** Financial writes assign timestamps inside transactions; capture pending writers before opening the read snapshot. */
            $oldestTransaction = DB::scalar('SELECT MIN(xact_start) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND xact_start IS NOT NULL');

            if ($oldestTransaction !== null) {
                $asOf = $asOf->min(CarbonImmutable::parse($oldestTransaction));
            }
        }

        $asOf = $asOf->startOfSecond()->toIso8601String();

        return DB::transaction(function () use ($owner, $filters, $asOf): array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            $cursor = $filters['cursor'] ?? null;
            $filters = [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'currency' => $filters['currency'] ?? 'all',
                'kind' => $filters['kind'] ?? 'all',
                'category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
                'search' => Str::squish($filters['search'] ?? ''),
                'void_state' => $filters['void_state'] ?? 'active',
                'updated_since' => isset($filters['updated_since']) ? CarbonImmutable::parse($filters['updated_since'])->utc()->format('Y-m-d\TH:i:s.uP') : null,
            ];
            $incremental = $filters['updated_since'] !== null;
            $column = $incremental ? 'updated_at' : 'occurred_on';
            $direction = $incremental ? 'asc' : 'desc';
            $binding = hash('sha256', json_encode([$owner->id, $filters], JSON_THROW_ON_ERROR));
            $position = null;

            if ($cursor !== null) {
                try {
                    $position = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                } catch (DecryptException|JsonException) {
                    throw ValidationException::withMessages(['cursor' => 'Invalid cursor. Start a new scan.']);
                }

                if (! is_array($position) || ($position['version'] ?? null) !== 1 || ($position['binding'] ?? null) !== $binding) {
                    throw ValidationException::withMessages(['cursor' => 'The cursor does not match this owner or these filters.']);
                }

                $asOf = $position['as_of'];
            }

            $query = $this->query($owner, $filters)->reorder()->orderBy($column, $direction)->orderBy('id', $direction)
                ->where('updated_at', '<=', $asOf);

            if ($incremental) {
                $query->where('updated_at', '>=', $filters['updated_since']);
            }

            if ($position !== null) {
                $operator = $incremental ? '>' : '<';
                $query->where(fn (Builder $query) => $query->where($column, $operator, $position['value'])
                    ->orWhere(fn (Builder $query) => $query->where($column, $position['value'])->where('id', $operator, $position['id'])));
            }

            $records = $query->limit(51)->get();
            $hasMore = $records->count() > 50;
            $records = $records->take(50);
            $last = $records->last();
            $nextCursor = $hasMore && $last !== null ? Crypt::encryptString(json_encode([
                'version' => 1, 'binding' => $binding, 'as_of' => $asOf,
                'value' => $incremental ? $last->updated_at?->toIso8601String() : $last->occurred_on->toDateString(),
                'id' => $last->id,
            ], JSON_THROW_ON_ERROR)) : null;

            $categories = $owner->categories()->get()->keyBy('id');

            return [
                'transactions' => array_values($records->map(fn (Transaction $transaction): array => $this->project($owner, $transaction, $categories))->all()),
                'order' => $incremental ? 'updated_at_asc_id_asc' : 'occurred_on_desc_id_desc',
                'as_of' => $asOf,
                'next_cursor' => $nextCursor,
                'checkpoint' => $hasMore ? null : $asOf,
            ];
        });
    }

    /** @return AgentTransaction|null */
    public function find(User $owner, int $id): ?array
    {
        return DB::transaction(function () use ($owner, $id): ?array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            $transaction = $this->query($owner, ['void_state' => 'all'])->find($id);

            return $transaction === null ? null : $this->project($owner, $transaction, $owner->categories()->get()->keyBy('id'));
        });
    }

    /**
     * @param  LedgerFiltersInput  $filters
     * @return Builder<Transaction>
     */
    private function query(User $owner, array $filters = []): Builder
    {
        return $this->ledger->query($owner, ['void_state' => 'active', ...$filters])
            ->addSelect(['user_id', 'updated_at'])
            ->with([
                'category' => fn ($query) => $query->whereBelongsTo($owner, 'owner'),
                'originalSpending' => fn ($query) => $query->whereBelongsTo($owner, 'owner'),
                'linkedRefunds' => fn ($query) => $query->whereBelongsTo($owner, 'owner')->orderByDesc('occurred_on')->orderByDesc('id'),
                'receiptBreakdown.lineItems',
            ]);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return AgentTransaction
     */
    private function project(User $owner, Transaction $transaction, Collection $categories): array
    {
        $row = $this->ledger->transactionData($transaction, $owner);
        $allocations = $this->breakdown->categoryAllocations($transaction);
        $projectedAllocations = [];

        foreach ($allocations as $id => $amount) {
            $projectedAllocations[] = ['category' => $this->category($categories->get($id)), 'amount_minor' => $amount->value()];
        }

        return [
            'id' => $row['id'],
            'occurred_on' => $row['occurred_on'],
            'amount_minor' => $row['amount_minor'],
            'currency' => $row['currency'],
            'kind' => $row['kind'],
            'direction' => $row['direction'],
            'income_source' => $row['income_source'],
            'transfer_purpose' => $row['transfer_purpose'],
            'description' => $row['description'],
            'confirmed_at' => $row['confirmed_at'],
            'review_state' => $row['review_state'],
            'updated_at' => $transaction->updated_at?->toIso8601String(),
            'voided_at' => $transaction->voided_at?->toIso8601String(),
            'category' => $transaction->kind->supportsCategory() ? $this->category($transaction->category) : null,
            'category_assignment_provenance' => $transaction->kind->supportsCategory() ? $transaction->category_assignment_provenance?->value : null,
            'original_spending' => $transaction->originalSpending === null ? null : $this->related($transaction->originalSpending),
            'linked_refunds' => array_values($transaction->linkedRefunds->map(fn (Transaction $refund): array => $this->related($refund))->all()),
            'category_allocations' => $projectedAllocations,
        ];
    }

    /** @return AgentCategory|null */
    private function category(?Category $category): ?array
    {
        return $category === null ? null : [
            'id' => $category->id, 'name' => $category->name, 'parent_id' => $category->parent_id,
            'archived_at' => $category->archived_at?->toIso8601String(),
        ];
    }

    /** @return RelatedTransaction */
    private function related(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id, 'occurred_on' => $transaction->occurred_on->toDateString(),
            'description' => $transaction->description, 'amount_minor' => (string) $transaction->amount_minor,
            'currency' => $transaction->currency->value, 'kind' => $transaction->kind->value,
            'direction' => $transaction->direction->value, 'voided_at' => $transaction->voided_at?->toIso8601String(),
        ];
    }
}
