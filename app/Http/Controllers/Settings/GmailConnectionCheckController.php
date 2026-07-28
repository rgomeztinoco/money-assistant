<?php

namespace App\Http\Controllers\Settings;

use App\Actions\NotificationIngestion\CheckGmailConnection;
use App\Http\Controllers\Controller;
use App\Integrations\Gmail\GmailReauthorizationRequired;
use App\Integrations\Gmail\GmailRequestFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GmailConnectionCheckController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, CheckGmailConnection $checkConnection): RedirectResponse
    {
        try {
            $connection = $checkConnection->handle($request->user());
        } catch (GmailReauthorizationRequired) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Gmail authorization expired. Reauthorize to resume ingestion.'),
            ]);

            return to_route('connections.edit');
        } catch (GmailRequestFailed) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Gmail could not be checked. Try again.'),
            ]);

            return to_route('connections.edit');
        }

        Inertia::flash('toast', [
            'type' => $connection === null ? 'error' : 'success',
            'message' => $connection === null
                ? __('Connect Gmail before checking it.')
                : __('Gmail connection is healthy.'),
        ]);

        return to_route('connections.edit');
    }
}
