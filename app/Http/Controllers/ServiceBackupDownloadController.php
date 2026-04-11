<?php

namespace App\Http\Controllers;

use App\Models\ServiceBackupRun;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams the per-service downloadable backup zip to the user.
 *
 * Auth model:
 *   - The user must be authenticated.
 *   - The backup row must belong to the user's currentTeam(),
 *     defense in depth so the route is never a way to read across
 *     teams.
 *   - If the user is a client (is_client = true), the project that
 *     owns the service must be in the user's assigned project list
 *     via the project_user pivot. This is the same gate the rest of
 *     the fork uses for client-scoped resources.
 *   - The row must be in `completed` status with a valid
 *     artifact_path AND not yet expired (expires_at in the future).
 *     ServiceBackupRun::isDownloadable() encapsulates the check.
 *
 * Why a controller class instead of a closure: same reason as
 * BackupsDownloadController — `php artisan route:cache` cannot
 * serialize closures, and Coolify's deploy step caches routes for
 * production, so a closure-based route would 404 in production.
 */
class ServiceBackupDownloadController extends Controller
{
    public function __invoke(int $id): BinaryFileResponse
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403, 'No autorizado.');
        }

        $teamId = (int) (currentTeam()?->id ?? 0);
        if ($teamId === 0) {
            abort(403, 'Sin equipo activo.');
        }

        $run = ServiceBackupRun::where('id', $id)
            ->where('team_id', $teamId)
            ->firstOrFail();

        // Client gate: only assigned-project access. Non-clients
        // already passed the team check above.
        //
        // We go straight at the DB here instead of walking the
        // Eloquent relations ($run->service->environment->project)
        // or using $user->assignedProjects()->pluck(...) because
        // Service, Environment and Project all mix in
        // RestrictsToClientProjects — the global scope would re-
        // enter recursively during the lazy relation loads and
        // returns nulls on edge cases (the exact bug that broke
        // the Livewire generate() path for a fresh client user).
        //
        // Two cheap reads:
        //   1. project_id that owns the service the run is tied to
        //   2. whether that project has a project_user pivot row
        //      for the authenticated user
        if ($user->isClient()) {
            $projectId = (int) (DB::table('services')
                ->join('environments', 'services.environment_id', '=', 'environments.id')
                ->where('services.id', $run->service_id)
                ->value('environments.project_id') ?? 0);
            if ($projectId === 0) {
                abort(404, 'El servicio asociado a este backup ya no existe.');
            }

            $hasAccess = DB::table('project_user')
                ->where('user_id', $user->id)
                ->where('project_id', $projectId)
                ->exists();
            if (! $hasAccess) {
                abort(403, 'No tienes acceso a este servicio.');
            }
        }

        if (! $run->isDownloadable()) {
            abort(404, 'El backup ya no está disponible (caducado o aún en proceso).');
        }

        $hostPath = (string) $run->artifact_path;

        // Translate the host path to the in-container path so the
        // PHP process inside the coolify web container can open
        // the file. backup_host_to_container_path() returns null
        // if the path is outside /data/coolify/backups, which
        // shouldn't happen for our writes but we guard for it.
        $containerPath = backup_host_to_container_path($hostPath);
        if ($containerPath === null || ! is_file($containerPath)) {
            abort(404, 'El archivo de backup ya no existe en disco.');
        }

        return response()->download($containerPath, $run->archive_name ?: basename($hostPath));
    }
}
