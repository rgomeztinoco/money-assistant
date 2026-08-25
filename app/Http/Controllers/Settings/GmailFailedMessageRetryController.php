<?php

namespace App\Http\Controllers\Settings;

use App\Actions\NotificationIngestion\RetryFailedGmailMessage;
use App\Http\Controllers\Controller;
use App\Models\GmailMessageDiscovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class GmailFailedMessageRetryController extends Controller
{
    public function __invoke(
        Request $request,
        GmailMessageDiscovery $gmailMessageDiscovery,
        RetryFailedGmailMessage $retryFailedGmailMessage,
    ): RedirectResponse {
        try {
            $retryFailedGmailMessage->handle(
                $request->user(),
                $gmailMessageDiscovery->id,
            );
        } catch (InvalidArgumentException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This Gmail message is no longer eligible for retry.'),
            ]);

            return to_route('data_sources.gmail');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The failed Gmail message was queued for retry.'),
        ]);

        return to_route('data_sources.gmail');
    }
}
