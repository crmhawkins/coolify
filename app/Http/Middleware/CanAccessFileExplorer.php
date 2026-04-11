<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the File Explorer routes. Broader access than the
 * terminal — admin, owner AND client — because the file
 * explorer is the primary way scoped client users interact with
 * their own sites (uploading themes, downloading backups,
 * extracting archives, etc.).
 *
 * Authorization check defers to the canAccessFileExplorer gate
 * defined in AuthServiceProvider so the gate stays the single
 * source of truth. RestrictsToClientProjects still filters the
 * target resource downstream via the global scope, so a client
 * who somehow reaches a URL for a service outside their assigned
 * projects gets a 404 from ->firstOrFail() in FileExplorer::mount().
 */
class CanAccessFileExplorer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            abort(401, 'Authentication required');
        }

        if (! Gate::allows('canAccessFileExplorer')) {
            abort(403, 'No tienes acceso al explorador de archivos.');
        }

        return $next($request);
    }
}
