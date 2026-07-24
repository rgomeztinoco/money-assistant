<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleAuthenticatedOpenClaw
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = $request->attributes->get('openclaw.key_id');
        $limiterKey = 'openclaw:authenticated:'.hash(
            'sha256',
            is_string($keyId) ? $keyId : '',
        );
        $maximumAttempts = max(
            1,
            (int) config('services.openclaw.capability.rate_limit_per_minute', 60),
        );

        if (RateLimiter::tooManyAttempts($limiterKey, $maximumAttempts)) {
            return $this->tooManyRequests($limiterKey);
        }

        RateLimiter::hit($limiterKey, 60);

        return $next($request);
    }

    private function tooManyRequests(string $limiterKey): JsonResponse
    {
        return response()->json(
            ['message' => 'Too many requests.'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => RateLimiter::availableIn($limiterKey)],
        );
    }
}
