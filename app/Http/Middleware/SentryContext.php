<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

class SentryContext
{
    /**
     * Attach the authenticated user's id (and ONLY the id) to the Sentry scope.
     *
     * No email/IP — send_default_pii is pinned false (NFR(isolation) privacy floor).
     * Guarded on app()->bound('sentry') so this is a no-op when no DSN is configured
     * (local/CI), and on an authenticated user so guests add nothing.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound('sentry') && $request->user() !== null) {
            configureScope(function (Scope $scope) use ($request): void {
                $scope->setUser(['id' => $request->user()->id]);
            });
        }

        return $next($request);
    }
}
