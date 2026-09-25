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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
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
            ->whereNull('voided_at')
            ->select([
                'id', 'user_id', 'occurred_on', 'amount_minor', 'currency',
                'kind', 'direction', 'income_source', 'transfer_purpose',
                'description', 'category_id',
            ])
            ->with('category:id,name');

        if ($filters['search'] !== '') {
            $literalSearch = addcslashes($filters['search'], '\\%_');
            $query->where('description', 'ilike', '%'.$literalSearch.'%');
        }

        $query
            ->when($filters['date_from'] !== null, fn (Builder $query) => $query->where('occurred_on', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== null, fn (Builder $query) => $query->where('occurred_on', '<=', $filters['date_to']))
            ->when($filters['currency'] !== null, fn (Builder $query) => $query->where('currency', $filters['currency']))
            ->when($filters['kinds'] !== [], fn (Builder $query) => $query->whereIn('kind', $filters['kinds']));

        $currency = Currency::tryFrom((string) $filters['currency']) ?? Currency::Pen;

        foreach (['amount_min' => '>=', 'amount_max' => '<='] as $field => $operator) {
            if ($filters[$field] !== null) {
                $minorUnits = CurrencyAmount::minorUnits((string) $filters[$field], $currency);
                $query->whereRaw("abs(amount_minor) {$operator} ?", [$minorUnits]);
            }
        }

        $page = $query
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return [
            'transactions' => $page->getCollection()->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'kind' => $transaction->kind->value,
                'direction' => $transaction->direction->value,
                'income_source' => $transaction->income_source?->value,
                'transfer_purpose' => $transaction->transfer_purpose?->value,
                'description' => $transaction->description,
                'category' => $transaction->category === null
                    ? null
                    : ['id' => $transaction->category->id, 'name' => $transaction->category->name],
            ])->all(),
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
