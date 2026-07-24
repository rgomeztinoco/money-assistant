<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class RequirePasskeyConfirmation
{
    public const string SESSION_KEY = 'auth.passkey_confirmed_at';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get(self::SESSION_KEY, 0);
        $confirmationAge = Date::now()->unix() - $confirmedAt;

        if ($confirmationAge >= 0 && $confirmationAge <= Config::integer('auth.password_timeout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Fresh passkey authentication is required.',
            ], 423);
        }

        $request->session()->put('url.intended', url()->previous());

        return redirect()->route('passkey.confirmation');
    }
}
