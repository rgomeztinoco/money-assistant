<?php

namespace App\Http\Controllers;

use App\Actions\Debts\RecordDebtEntry;
use App\Actions\Debts\UpdateDebtEntry;
use App\DebtEntryKind;
use App\Http\Requests\StoreDebtEntryRequest;
use App\Http\Requests\UpdateDebtEntryRequest;
use App\Models\Debt;
use App\Models\DebtEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class DebtEntryController extends Controller
{
    public function update(UpdateDebtEntryRequest $request, Debt $debt, DebtEntry $entry, UpdateDebtEntry $updateDebtEntry): RedirectResponse
    {
        $validated = $request->validated();
        $updateDebtEntry->handle($request->user(), $debt, $entry, CarbonImmutable::parse($validated['occurred_on']),
            $entry->kind === DebtEntryKind::Adjustment ? (int) $validated['amount_minor'] : $request->amountMinor(),
            $validated['description'] ?? null, $validated['reason'] ?? null,
            $request->debtAllocationMinor('principal'), $request->debtAllocationMinor('interest'),
            $request->has('category_id') ? ($request->integer('category_id') ?: null) : $entry->transaction?->category_id);

        return to_route('debts.show', $debt);
    }

    public function store(StoreDebtEntryRequest $request, Debt $debt, RecordDebtEntry $recordDebtEntry): RedirectResponse
    {
        $recordDebtEntry->handle($request->user(), $debt, DebtEntryKind::from($request->string('kind')->toString()),
            $request->filled('occurred_on') ? CarbonImmutable::parse($request->string('occurred_on')->toString()) : null,
            $request->filled('transaction_id') ? null : ($request->input('kind') === 'adjustment' ? $request->integer('amount_minor') : $request->amountMinor()),
            $request->input('description'), $request->input('reason'), $request->integer('transaction_id') ?: null,
            $request->debtAllocationMinor('principal'), $request->debtAllocationMinor('interest'), $request->boolean('interest_is_new'), $request->integer('category_id') ?: null);

        return to_route('debts.show', $debt);
    }
}
