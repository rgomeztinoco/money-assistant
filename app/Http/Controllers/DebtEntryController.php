<?php

namespace App\Http\Controllers;

use App\Actions\Debts\RecordDebtEntry;
use App\DebtEntryKind;
use App\Http\Requests\StoreDebtEntryRequest;
use App\Models\Debt;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class DebtEntryController extends Controller
{
    public function store(StoreDebtEntryRequest $request, Debt $debt, RecordDebtEntry $recordDebtEntry): RedirectResponse
    {
        $recordDebtEntry->handle($request->user(), $debt, DebtEntryKind::from($request->string('kind')->toString()),
            $request->filled('occurred_on') ? CarbonImmutable::parse($request->string('occurred_on')->toString()) : null,
            $request->filled('transaction_id') ? null : ($request->input('kind') === 'adjustment' ? $request->integer('amount_minor') : $request->amountMinor()),
            $request->input('description'), $request->input('reason'), $request->integer('transaction_id') ?: null);

        return to_route('debts.show', $debt);
    }
}
