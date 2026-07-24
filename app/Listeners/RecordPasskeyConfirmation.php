<?php

namespace App\Listeners;

use App\Http\Middleware\RequirePasskeyConfirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Passkeys\Events\PasskeyVerified;

class RecordPasskeyConfirmation
{
    /**
     * Create the event listener.
     */
    public function __construct(private Request $request) {}

    /**
     * Handle the event.
     */
    public function handle(PasskeyVerified $event): void
    {
        if (! $this->request->routeIs('passkey.confirm')) {
            return;
        }

        $this->request->session()->put(RequirePasskeyConfirmation::SESSION_KEY, Date::now()->unix());
    }
}
