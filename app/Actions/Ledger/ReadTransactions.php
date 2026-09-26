<?php

namespace App\Actions\Ledger;

use App\Currency;
use App\CurrencyAmount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReadTransactions
{
    /**
     * @param  array{search?: string|null, date_from?: string|null, date_to?: string|null, currency?: string|null, amount_min?: string|null, amount_max?: string|null, kinds?: list<string>, selected?: int|string|null, page?: int|string|null}  $input
     * @return array{
     *     transactions: list<array{id: int, occurred_on: string, amount_minor: string, currency: string, kind: string, direction: string, income_source: string|null, transfer_purpose: string|null, description: string, has_split: bool, category: array{id: int, name: string}|null}>,
     *     pagination: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, previous_page_url: string|null, next_page_url: string|null},
     *     filters: array{search: string, date_from: string|null, date_to: string|null, currency: string|null, amount_min: string|null, amount_max: string|null, kinds: list<string>}
     * }
     */
    public function handle(User $owner, array $input): array
    {
        $filters = [
            'search' => trim((string) ($input['search'] ?? '')),
            'date_from' => $input['date_from'] ?? null,
            'date_to' => $input['date_to'] ?? null,
            'currency' => $input['currency'] ?? null,
            'amount_min' => $input['amount_min'] ?? null,
            'amount_max' => $input['amount_max'] ?? null,
            'kinds' => $input['kinds'] ?? [],
        ];
        $query = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->select([
                'id', 'user_id', 'occurred_on', 'amount_minor', 'currency',
                'kind', 'direction', 'income_source', 'transfer_purpose',
                'description', 'category_id', 'voided_at',
            ])
            ->with(['category:id,name', 'receiptBreakdown:id,transaction_id']);
        $searchId = preg_match('/^#?([1-9][0-9]*)$/D', $filters['search'], $matches) === 1
            ? filter_var($matches[1], FILTER_VALIDATE_INT)
            : false;
        $currency = Currency::tryFrom((string) $filters['currency']) ?? Currency::Pen;

        $query->where(function (Builder $query) use ($filters, $currency, $searchId): void {
            $query->where(function (Builder $query) use ($filters, $currency): void {
                $query->whereNull('voided_at');

                if ($filters['search'] !== '') {
                    $literalSearch = addcslashes($filters['search'], '\\%_');
                    $query->where('description', 'ilike', '%'.$literalSearch.'%');
                }

                $query
                    ->when($filters['date_from'] !== null, fn (Builder $query) => $query->where('occurred_on', '>=', $filters['date_from']))
                    ->when($filters['date_to'] !== null, fn (Builder $query) => $query->where('occurred_on', '<=', $filters['date_to']))
                    ->when($filters['currency'] !== null, fn (Builder $query) => $query->where('currency', $filters['currency']))
                    ->when($filters['kinds'] !== [], fn (Builder $query) => $query->whereIn('kind', $filters['kinds']));

                foreach (['amount_min' => '>=', 'amount_max' => '<='] as $field => $operator) {
                    if ($filters[$field] !== null) {
                        $minorUnits = CurrencyAmount::minorUnits((string) $filters[$field], $currency);
                        $query->whereRaw("abs(amount_minor) {$operator} ?", [$minorUnits]);
                    }
                }
            });

            if ($searchId !== false) {
                $query->orWhere('id', $searchId);
            }
        });

        $page = $query
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return [
            'transactions' => array_values($page->getCollection()->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'kind' => $transaction->kind->value,
                'direction' => $transaction->direction->value,
                'income_source' => $transaction->income_source?->value,
                'transfer_purpose' => $transaction->transfer_purpose?->value,
                'description' => $transaction->description,
                'voided_at' => $transaction->voided_at?->toIso8601String(),
                'has_split' => $transaction->receiptBreakdown !== null,
                'category' => $transaction->category === null
                    ? null
                    : ['id' => $transaction->category->id, 'name' => $transaction->category->name],
            ])->all()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'previous_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'filters' => $filters,
        ];
    }
}
