<?php

namespace App\Http\Controllers;

use App\Actions\Debts\ReadDebts;
use App\Actions\Debts\SaveDebt;
use App\Http\Requests\SaveDebtRequest;
use App\Models\Debt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DebtController extends Controller
{
    public function __construct(private SaveDebt $saveDebt, private ReadDebts $readDebts) {}

    public function index(Request $request): Response
    {
        return Inertia::render('debts/index', ['debts' => $this->readDebts->handle($request->user()), 'today' => now(config('app.reporting_timezone'))->toDateString()]);
    }

    public function store(SaveDebtRequest $request): RedirectResponse
    {
        $debt = $this->saveDebt->handle($request->user(), null, $request->debtData());

        return to_route('debts.show', $debt);
    }

    public function update(SaveDebtRequest $request, Debt $debt): RedirectResponse
    {
        $this->saveDebt->handle($request->user(), $debt, $request->debtData());

        return to_route('debts.show', $debt);
    }

    public function show(Request $request, Debt $debt): Response
    {
        abort_unless($debt->user_id === $request->user()->id, 404);

        return Inertia::render('debts/show', ['debt' => $this->readDebts->debtData($debt), 'today' => now(config('app.reporting_timezone'))->toDateString(), 'entries' => $this->readDebts->entries($debt), 'transaction_options' => $this->readDebts->transactionOptions($request->user(), $debt)]);
    }
}
