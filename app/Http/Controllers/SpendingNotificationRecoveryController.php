<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\RecoverSpendingNotification;
use App\Currency;
use App\Http\Requests\StoreSpendingNotificationRecoveryRequest;
use App\Models\SpendingNotificationReference;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class SpendingNotificationRecoveryController extends Controller
{
    public function store(
        StoreSpendingNotificationRecoveryRequest $request,
        SpendingNotificationReference $spendingNotificationReference,
        RecoverSpendingNotification $recoverSpendingNotification,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $recoverSpendingNotification->handle(
                owner: $request->user(),
                reference: $spendingNotificationReference,
                occurredOn: CarbonImmutable::parse(
                    $validated['occurred_on'],
                    config('app.timezone'),
                ),
                amountMinor: (int) $validated['amount_minor'],
                currency: Currency::from($validated['currency']),
                kind: TransactionKind::from($validated['kind']),
                description: $validated['description'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'recovery' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Unsupported Spending Notification recorded and linked.'),
        ]);

        return to_route('parser_profiles.index');
    }
}
