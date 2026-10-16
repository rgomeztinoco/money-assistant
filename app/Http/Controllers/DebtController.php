<?php

namespace App\Http\Controllers;

use App\Actions\Debts\ReadDebts;
use App\Actions\Debts\SaveDebt;
use App\Actions\Debts\SettleDebt;
use App\Http\Requests\SaveDebtRequest;
use App\Models\Debt;
use App\MovementDirection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        return redirect()->back(fallback: route('debts.show', $debt));
    }

    public function settle(Request $request, Debt $debt, SettleDebt $settleDebt): RedirectResponse
    {
        $settleDebt->handle($request->user(), $debt);

        return to_route('debts.index');
    }

    public function show(Request $request, Debt $debt): Response
    {
        abort_unless($debt->user_id === $request->user()->id, 404);

        return Inertia::render('debts/show', ['debt' => $this->readDebts->debtData($debt), 'today' => now(config('app.reporting_timezone'))->toDateString(), 'entries' => $this->readDebts->entries($debt)]);
    }

    public function transactions(Request $request, Debt $debt): JsonResponse
    {
        abort_unless($debt->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'direction' => ['required', Rule::enum(MovementDirection::class)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        $transactions = $this->readDebts->transactionOptions($request->user(), $debt, MovementDirection::from($validated['direction']), trim($validated['search'] ?? ''), (int) ($validated['page'] ?? 1));

        return response()->json(['transactions' => array_slice($transactions, 0, 50), 'has_more' => count($transactions) > 50]);
    }
}
