<?php

namespace App\Http\Controllers\Settings;

use App\Actions\NotificationIngestion\CompleteGmailAuthorization;
use App\Contracts\Gmail;
use App\Http\Controllers\Controller;
use App\Integrations\Gmail\GmailRequestFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GmailAuthorizationController extends Controller
{
    public function create(Request $request, Gmail $gmail): RedirectResponse
    {
        abort_unless(
            filled(config('services.gmail.client_id'))
                && filled(config('services.gmail.client_secret'))
                && filled(config('services.gmail.redirect_uri'))
                && config('services.gmail.oauth_publishing_status') === 'production',
            503,
        );

        $state = bin2hex(random_bytes(32));

        $request->session()->put('gmail_oauth_state', [
            'state' => $state,
            'user_id' => $request->user()->getAuthIdentifier(),
        ]);

        return redirect()->away($gmail->authorizationUrl($state, $request->user()->email));
    }

    public function store(Request $request, CompleteGmailAuthorization $completeAuthorization): RedirectResponse
    {
        $expected = $request->session()->pull('gmail_oauth_state');
        $state = $request->query('state');

        abort_unless(
            is_array($expected)
                && is_string($expected['state'] ?? null)
                && $expected['user_id'] === $request->user()->getAuthIdentifier()
                && is_string($state)
                && hash_equals($expected['state'], $state),
            419,
        );

        if (is_string($request->query('error'))) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Gmail authorization was not granted.'),
            ]);

            return to_route('connections.edit');
        }

        $code = $request->query('code');

        abort_unless(is_string($code) && $code !== '', 419);

        try {
            $completeAuthorization->handle($code);
        } catch (GmailRequestFailed) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Gmail could not be connected. Try reauthorizing.'),
            ]);

            return to_route('connections.edit');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Gmail connected.')]);

        return to_route('connections.edit');
    }
}
