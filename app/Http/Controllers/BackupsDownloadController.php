<?php

namespace App\Http\Controllers;

use App\Models\TeamBackupRun;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams the tarball of a completed local team backup to the
 * browser.
 *
 * Lives as a dedicated controller rather than an inline closure
 * inside routes/web.php because Laravel's route cache CANNOT
 * serialize closures — on a production Coolify install that runs
 * `php artisan route:cache` during deploy, closure-based routes
 * either fail the cache build or get silently dropped, causing
 * 404s for routes that render fine from blade's route() helper.
 * A plain controller class is fully cacheable.
 *
 * Authorization:
 *   - requires an authenticated non-client user (enforced by the
 *     `restrict.client` middleware declared in routes/web.php)
 *   - the backup run must belong to the requester's current team
 *   - only `local` runs in the `completed` status can be
 *     downloaded (sftp runs have no local artifact)
 *   - the tarball must still exist on disk (retention may have
 *     already deleted it)
 */
class BackupsDownloadController extends Controller
{
    public function __invoke(int $id): BinaryFileResponse
    {
        if (auth()->user()?->isClient()) {
            abort(403, 'Los clientes no pueden descargar backups globales.');
        }

        $teamId = (int) (currentTeam()?->id ?? 0);

        $run = TeamBackupRun::where('id', $id)
            ->where('team_id', $teamId)
            ->where('destination', 'local')
            ->where('status', 'completed')
            ->firstOrFail();

        $path = (string) $run->artifact_path;
        if ($path === '' || ! is_file($path)) {
            abort(404, 'El archivo de backup ya no existe en disco.');
        }

        return response()->download($path, basename($path));
    }
}
