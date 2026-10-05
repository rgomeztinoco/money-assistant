<?php

namespace App\Http\Controllers\Settings;

use App\Actions\NotificationIngestion\ReadGmailReview;
use App\Actions\NotificationIngestion\RetryGmailReviewMessage;
use App\Http\Controllers\Controller;
use App\Models\GmailMessageDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class GmailReviewMessageController extends Controller
{
    public function dismiss(Request $request, GmailMessageDiscovery $gmailMessageDiscovery, ReadGmailReview $readGmailReview): RedirectResponse|JsonResponse
    {
        $discovery = $this->forOwner($request, $gmailMessageDiscovery);

        if (! $discovery->needsReview()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('This email no longer needs review.')], 409);
            }
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This email no longer needs review.'),
            ]);

            return back();
        }

        if ($discovery->dismissed_at === null) {
            $discovery->update(['dismissed_at' => now()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'review' => $readGmailReview->handle($request->user(), $request->string('view')->toString(), includeSummaries: false),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Email dismissed from the attention list.'),
        ]);

        return back();
    }

    public function restore(Request $request, GmailMessageDiscovery $gmailMessageDiscovery): RedirectResponse
    {
        $discovery = $this->forOwner($request, $gmailMessageDiscovery);

        if ($discovery->dismissed_at !== null) {
            $discovery->update(['dismissed_at' => null]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Email restored.'),
        ]);

        return back();
    }

    public function retry(
        Request $request,
        GmailMessageDiscovery $gmailMessageDiscovery,
        RetryGmailReviewMessage $retryGmailReviewMessage,
    ): RedirectResponse {
        try {
            $retryGmailReviewMessage->handle($request->user(), $gmailMessageDiscovery->id);
        } catch (InvalidArgumentException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This Gmail message is no longer eligible for retry.'),
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Email queued for retry. Processing has not finished yet.'),
        ]);

        return back();
    }

    private function forOwner(Request $request, GmailMessageDiscovery $discovery): GmailMessageDiscovery
    {
        return GmailMessageDiscovery::query()
            ->with('reference')
            ->whereKey($discovery->id)
            ->whereHas('gmailConnection', fn ($query) => $query->whereBelongsTo($request->user(), 'owner'))
            ->firstOrFail();
    }
}
