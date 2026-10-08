<?php

namespace App\Actions\Debts;

use App\Models\Debt;
use App\Models\DebtEntry;
use App\Models\Transaction;
use App\Models\User;

/** @phpstan-type DebtData array{id: int, name: string, counterparty: string, direction: string, currency: string, opening_balance_minor: string, opened_on: string, balance_minor: string, status: string} */
class ReadDebts
{
    /** @return list<array{id: int, name: string, currency: string, direction: string}> */
    public function options(User $owner): array
    {
        return array_values(Debt::query()->whereBelongsTo($owner, 'owner')->orderBy('name')->get(['id', 'name', 'currency', 'direction'])
            ->map(fn (Debt $debt): array => ['id' => $debt->id, 'name' => $debt->name, 'currency' => $debt->currency->value, 'direction' => $debt->direction->value])->all());
    }

    /** @return list<DebtData> */
    public function handle(User $owner): array
    {
        return array_values(Debt::query()->whereBelongsTo($owner, 'owner')->with('entries.transaction')->orderBy('name')->get()
            ->map(fn (Debt $debt): array => $this->debtData($debt))->all());
    }

    /** @return list<array{id: int, kind: string, amount_minor: string, occurred_on: string, reason: string|null, transaction_id: int|null, voided: bool}> */
    public function entries(Debt $debt): array
    {
        return array_values($debt->entries()->with('transaction')->orderByDesc('occurred_on')->orderByDesc('id')->get()->map(fn (DebtEntry $entry): array => [
            'id' => $entry->id, 'kind' => $entry->kind->value, 'amount_minor' => (string) $entry->amount_minor,
            'occurred_on' => $entry->occurred_on->toDateString(), 'reason' => $entry->reason,
            'transaction_id' => $entry->transaction_id, 'voided' => $entry->transaction?->voided_at !== null,
        ])->all());
    }

    /** @return list<array{id: int, occurred_on: string, amount_minor: string, currency: string, direction: string, description: string}> */
    public function transactionOptions(User $owner, Debt $debt): array
    {
        return array_values(Transaction::query()->whereBelongsTo($owner, 'owner')
            ->where('currency', $debt->currency)->where('occurred_on', '>=', $debt->opened_on->toDateString())
            ->whereNull('voided_at')->whereDoesntHave('debtEntry')->orderByDesc('occurred_on')->orderByDesc('id')->limit(100)->get()
            ->map(fn (Transaction $transaction): array => ['id' => $transaction->id,
                'occurred_on' => $transaction->occurred_on->toDateString(), 'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value, 'direction' => $transaction->direction->value, 'description' => $transaction->description])->all());
    }

    /** @return DebtData */
    public function debtData(Debt $debt): array
    {
        $balance = $debt->balance()->value();

        return ['id' => $debt->id, 'name' => $debt->name, 'counterparty' => $debt->counterparty,
            'direction' => $debt->direction->value, 'currency' => $debt->currency->value,
            'opening_balance_minor' => (string) $debt->opening_balance_minor, 'opened_on' => $debt->opened_on->toDateString(),
            'balance_minor' => $balance, 'status' => $balance === '0' ? 'settled' : 'active'];
    }
}
