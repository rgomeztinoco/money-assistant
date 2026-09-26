<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GmailConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GmailConnectionDisconnectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $connection = GmailConnection::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->first();

        if ($connection === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Gmail is already disconnected.'),
            ]);

            return to_route('data_sources.gmail');
        }

        $connection->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Gmail disconnected. Existing Transactions are unchanged.'),
        ]);

        return to_route('data_sources.gmail');
    }
}
