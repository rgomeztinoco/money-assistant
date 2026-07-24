<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordForOwnerEmailChange
{
    public function __construct(private RequirePassword $requirePassword) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $owner = $request->user();

        if ($owner instanceof User && $request->string('email')->toString() === $owner->email) {
            return $next($request);
        }

        return $this->requirePassword->handle($request, $next);
    }
}
