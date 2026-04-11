<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards routes that open the "shell inside a container" feature.
 *
 * This is ADMIN/OWNER only on purpose — see the canAccessTerminal
 * gate definition in AuthServiceProvider for the rationale. The
 * middleware defers entirely to the gate so gate and middleware
 * can never drift apart (the previous version duplicated the
 * check inline and drifted at least once when the client role
 * was added).
 */
class CanAccessTerminal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            abort(401, 'Authentication required');
        }

        if (! Gate::allows('canAccessTerminal')) {
            abort(403, 'Access to terminal functionality is restricted to team administrators.');
        }

        return $next($request);
    }
}
// resync-marker 2026-04-08
