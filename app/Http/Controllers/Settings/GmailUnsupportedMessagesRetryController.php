<?php

namespace App\Http\Controllers\Settings;

use App\Actions\NotificationIngestion\RetryUnsupportedGmailMessages;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GmailUnsupportedMessagesRetryController extends Controller
{
    public function __invoke(
        Request $request,
        RetryUnsupportedGmailMessages $retryUnsupportedGmailMessages,
    ): RedirectResponse {
        $queuedCount = $retryUnsupportedGmailMessages->handle($request->user());

        Inertia::flash('toast', [
            'type' => $queuedCount === 0 ? 'error' : 'success',
            'message' => $queuedCount === 0
                ? __('No unsupported Gmail notifications are eligible for retry.')
                : trans_choice(
                    '{1} One unsupported Gmail notification was queued for retry.|[2,*] :count unsupported Gmail notifications were queued for retry.',
                    $queuedCount,
                    ['count' => $queuedCount],
                ),
        ]);

        return to_route('data_sources.gmail');
    }
}
