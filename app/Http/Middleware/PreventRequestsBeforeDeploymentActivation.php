<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventRequestsBeforeDeploymentActivation
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isActivated = is_file((string) config('app.deployment_activation_marker'));

        if (! config('app.deployment_requests_enabled') && ! $isActivated && ! $request->is('up')) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $next($request);
    }
}
