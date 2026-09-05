<?php

namespace App\Http\Controllers\Settings;

use App\GmailSynchronizationType;
use App\Http\Controllers\Controller;
use App\Jobs\SynchronizeGmail;
use App\Models\GmailConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GmailImportController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $connection = GmailConnection::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->first();

        if ($connection === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Connect Gmail before importing.'),
            ]);

            return to_route('data_sources.gmail');
        }

        if ($connection->ingestionIsPaused()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Reconnect Gmail before importing.'),
            ]);

            return to_route('data_sources.gmail');
        }

        SynchronizeGmail::dispatch(
            $connection->id,
            GmailSynchronizationType::Incremental,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Gmail import started.'),
        ]);

        return to_route('data_sources.gmail');
    }
}
