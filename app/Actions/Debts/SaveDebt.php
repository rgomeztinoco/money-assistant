<?php

namespace App\Actions\Debts;

use App\Models\Debt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveDebt
{
    /** @param array{name: string, counterparty: string, direction: string, currency: string, opening_balance_minor: int|string, opened_on: string, monthly_target_minor: int|null} $data */
    public function handle(User $owner, ?Debt $debt, array $data): Debt
    {
        return DB::transaction(function () use ($owner, $debt, $data): Debt {
            $debt = $debt === null ? new Debt : Debt::query()->whereBelongsTo($owner, 'owner')->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if ($debt->exists && $debt->entries()->exists()) {
                if ($data['currency'] !== $debt->currency->value || $data['direction'] !== $debt->direction->value) {
                    throw ValidationException::withMessages(['currency' => 'Currency and debt direction cannot change while history exists.']);
                }
                if ($debt->entries()->where('occurred_on', '<', $data['opened_on'])->exists()) {
                    throw ValidationException::withMessages(['opened_on' => 'The opening date cannot be after debt history.']);
                }
            }
            $debt->fill([...$data, 'user_id' => $owner->id, 'name' => Str::squish($data['name']), 'counterparty' => Str::squish($data['counterparty'])])->save();

            return $debt;
        });
    }
}
