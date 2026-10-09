<?php

namespace App\Actions\Debts;

use App\Currency;
use App\DebtDirection;
use App\DebtEntryKind;
use App\ExactInteger;
use App\Models\Debt;
use App\Models\DebtEntry;
use App\Models\Transaction;
use App\Models\User;

/** @phpstan-type DebtData array{id: int, name: string, counterparty: string, direction: string, currency: string, opening_balance_minor: string, opened_on: string, balance_minor: string, status: string, monthly_target_minor: string|null, monthly_paid_minor: string, target_month: string} */
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

    /** @return list<array{id: int, kind: string, amount_minor: string, occurred_on: string, reason: string|null, transaction_id: int|null, principal_minor: string|null, interest_minor: string, voided: bool}> */
    public function entries(Debt $debt): array
    {
        return array_values($debt->entries()->with('transaction')->orderByDesc('occurred_on')->orderByDesc('id')->get()->map(fn (DebtEntry $entry): array => [
            'id' => $entry->id, 'kind' => $entry->kind->value, 'amount_minor' => (string) $entry->amount_minor,
            'occurred_on' => $entry->occurred_on->toDateString(), 'reason' => $entry->reason,
            'transaction_id' => $entry->transaction_id, 'principal_minor' => $entry->principal_minor === null ? null : (string) $entry->principal_minor, 'interest_minor' => (string) $entry->interest_minor, 'voided' => $entry->transaction?->voided_at !== null,
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

    /** @return array<string, mixed> */
    public function summary(User $owner): array
    {
        $summary = ['target_month' => now(config('app.reporting_timezone'))->format('Y-m')];
        $debts = $this->handle($owner);
        foreach (Currency::cases() as $currency) {
            $totals = array_fill_keys(['owed_minor', 'receivable_minor', 'outgoing_target_minor', 'incoming_target_minor', 'payments_made_minor', 'payments_received_minor'], ExactInteger::from(0));
            foreach ($debts as $debt) {
                if ($debt['currency'] !== $currency->value) {
                    continue;
                }
                $owed = $debt['direction'] === DebtDirection::Owed->value;
                $key = $owed ? 'owed_minor' : 'receivable_minor';
                $totals[$key] = $totals[$key]->add(ExactInteger::from($debt['balance_minor']));
                $targetKey = $owed ? 'outgoing_target_minor' : 'incoming_target_minor';
                if ($debt['status'] === 'active') {
                    $totals[$targetKey] = $totals[$targetKey]->add(ExactInteger::from($debt['monthly_target_minor'] ?? 0));
                }
                $paidKey = $owed ? 'payments_made_minor' : 'payments_received_minor';
                $totals[$paidKey] = $totals[$paidKey]->add(ExactInteger::from($debt['monthly_paid_minor']));
            }
            $summary[$currency->value] = array_map(fn (ExactInteger $amount): string => $amount->value(), $totals);
        }

        return $summary;
    }

    /** @return DebtData */
    public function debtData(Debt $debt): array
    {
        $balance = $debt->balance()->value();
        $month = now(config('app.reporting_timezone'))->format('Y-m');
        $paid = ExactInteger::from(0);
        foreach ($debt->entries as $entry) {
            if ($entry->kind === DebtEntryKind::Repayment && $entry->transaction?->voided_at === null && $entry->occurred_on->format('Y-m') === $month) {
                $paid = $paid->add(ExactInteger::from($entry->amount_minor));
            }
        }

        return ['id' => $debt->id, 'name' => $debt->name, 'counterparty' => $debt->counterparty,
            'direction' => $debt->direction->value, 'currency' => $debt->currency->value,
            'opening_balance_minor' => (string) $debt->opening_balance_minor, 'opened_on' => $debt->opened_on->toDateString(),
            'monthly_target_minor' => $debt->monthly_target_minor === null ? null : (string) $debt->monthly_target_minor, 'monthly_paid_minor' => $paid->value(), 'target_month' => $month,
            'balance_minor' => $balance, 'status' => $balance === '0' ? 'settled' : 'active'];
    }
}
