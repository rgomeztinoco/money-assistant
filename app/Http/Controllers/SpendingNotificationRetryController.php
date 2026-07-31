<?php

namespace App\Http\Controllers;

use App\Actions\NotificationIngestion\RetrySpendingNotification;
use App\Http\Requests\RetrySpendingNotificationRequest;
use App\Models\SpendingNotificationReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class SpendingNotificationRetryController extends Controller
{
    public function store(
        RetrySpendingNotificationRequest $request,
        SpendingNotificationReference $spendingNotificationReference,
        RetrySpendingNotification $retrySpendingNotification,
    ): RedirectResponse {
        try {
            $retrySpendingNotification->handle(
                $request->user(),
                $spendingNotificationReference,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'retry' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Spending Notification retried with the current approved profile.'),
        ]);

        return to_route('parser_profiles.index');
    }
}
