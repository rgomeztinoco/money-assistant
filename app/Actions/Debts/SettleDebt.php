<?php

namespace App\Actions\Debts;

use App\DebtEntryKind;
use App\ExactInteger;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettleDebt
{
    public function handle(User $owner, Debt $debt): void
    {
        DB::transaction(function () use ($owner, $debt): void {
            $debt = Debt::query()->whereBelongsTo($owner, 'owner')->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $balance = $debt->balance();

            if ($balance->compare(ExactInteger::from(0)) === 0) {
                return;
            }

            $today = now(config('app.reporting_timezone'))->startOfDay();
            if ($today->lt($debt->opened_on)) {
                throw ValidationException::withMessages(['settlement' => 'The debt cannot be settled before its opening date.']);
            }
            if ($balance->compare(ExactInteger::from(PHP_INT_MAX)) > 0) {
                throw ValidationException::withMessages(['settlement' => 'This balance is too large for one adjustment. Reduce it with smaller adjustments first.']);
            }

            $debt->entries()->create([
                'kind' => DebtEntryKind::Adjustment,
                'amount_minor' => ExactInteger::from(0)->subtract($balance)->value(),
                'occurred_on' => $today,
                'reason' => 'Debt settled by adjustment',
            ]);
        }, 3);
    }
}
