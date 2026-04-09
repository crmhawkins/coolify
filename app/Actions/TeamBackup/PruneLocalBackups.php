<?php

namespace App\Actions\TeamBackup;

use App\Models\Server;
use App\Models\Team;
use App\Models\TeamBackupRun;
use App\Models\TeamBackupSetting;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Enforces local retention for a team's backup history.
 *
 * Runs after every successful local backup. Lists all local
 * `completed` runs for the team ordered by newest first, keeps the
 * top N (N = settings.local_retention), and deletes the tarball
 * file + marks the DB row for anything past the cutoff.
 *
 * Safe to run multiple times — a row whose artifact is already
 * gone just flips its status to "pruned" and is left alone.
 */
class PruneLocalBackups
{
    use AsAction;

    public function handle(int $teamId): int
    {
        $settings = TeamBackupSetting::forTeam($teamId);
        $keep = max(1, (int) $settings->local_retention);

        $completed = TeamBackupRun::query()
            ->where('team_id', $teamId)
            ->where('destination', 'local')
            ->where('status', 'completed')
            ->whereNotNull('artifact_path')
            ->latest('created_at')
            ->get();

        if ($completed->count() <= $keep) {
            return 0;
        }

        $server = Server::find(0);
        $deleted = 0;
        $toDelete = $completed->slice($keep);
        foreach ($toDelete as $run) {
            $path = (string) $run->artifact_path;
            if ($path !== '' && $server instanceof Server) {
                try {
                    instant_remote_process([
                        'rm -f '.escapeshellarg($path),
                    ], $server, throwError: false);
                } catch (\Throwable $e) {
                    // ignored — DB row still flips below
                }
            }
            $run->update([
                'status' => 'pruned',
                'last_message' => 'Eliminado por política de retención.',
            ]);
            $deleted++;
        }

        return $deleted;
    }
}
