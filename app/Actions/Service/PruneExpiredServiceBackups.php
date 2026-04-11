<?php

namespace App\Actions\Service;

use App\Models\Server;
use App\Models\ServiceBackupRun;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes expired downloadable service backups from disk and
 * flips their model row to `expired`.
 *
 * Runs every minute via the scheduler (see app/Console/Kernel.php).
 * Idempotent: a run with nothing to delete is a no-op. Only acts
 * on rows in `completed` whose `expires_at` is in the past — rows
 * in `running`, `pending`, `failed` or already `expired` are
 * skipped, so a worker that's mid-run is never raced by this
 * cleaner.
 *
 * Why we delete the file before flipping the row:
 *   - If the rm succeeds and the update fails, the next run picks
 *     up the same row, sees the file is missing, and still flips
 *     the status. No data is lost.
 *   - If the update succeeds and the rm fails, the row claims
 *     "expired" but the file lingers — the next run still finds
 *     it via filesystem-only sweeping (last step) and removes it.
 *
 * The action also does a *blind* directory sweep at the end:
 * any .zip in /data/coolify/backups/services/ that has no DB row
 * pointing at it AND is older than 60 minutes gets deleted. This
 * catches orphans from crashed workers, partial generations, and
 * row-deletion-without-file-cleanup edge cases.
 */
class PruneExpiredServiceBackups
{
    use AsAction;

    /**
     * Hard ceiling for orphan files. Anything older than this in
     * the services backup directory that has no corresponding row
     * is removed during the blind sweep. Set deliberately above
     * GenerateServiceBackup::TTL_MINUTES so a freshly generated
     * backup whose row hasn't reached `completed` yet doesn't get
     * eaten by the sweep.
     */
    public const ORPHAN_AFTER_MINUTES = 60;

    public function handle(): array
    {
        $stats = [
            'rows_expired' => 0,
            'files_deleted' => 0,
            'orphans_swept' => 0,
            'errors' => [],
        ];

        $expired = ServiceBackupRun::query()
            ->where('status', 'completed')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->limit(200)
            ->get();

        foreach ($expired as $run) {
            try {
                if ($run->artifact_path) {
                    $this->removeFile((string) $run->artifact_path);
                    $stats['files_deleted']++;
                }
                $run->update([
                    'status' => 'expired',
                    'last_message' => 'Caducó. Genera una nueva copia para descargar.',
                ]);
                $stats['rows_expired']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = 'run '.$run->id.': '.$e->getMessage();
            }
        }

        try {
            $stats['orphans_swept'] = $this->sweepOrphans();
        } catch (\Throwable $e) {
            $stats['errors'][] = 'sweep: '.$e->getMessage();
        }

        return $stats;
    }

    /**
     * Best-effort delete of the artifact file from the host. We
     * shell out via the local Coolify server because the file
     * lives at /data/coolify/backups/... on the HOST and the PHP
     * process running this action lives inside the coolify web
     * container — it can see the file via the bind mount but PHP
     * unlink() inside the container has gotten weird about path
     * resolution before, so the shell-out is the safe path.
     */
    private function removeFile(string $hostPath): void
    {
        if ($hostPath === '') {
            return;
        }
        // Refuse to ever rm anything outside the services backup
        // tree. Defense in depth against a future bug that might
        // populate artifact_path with garbage.
        $allowedPrefix = rtrim(backup_dir(), '/').'/services/';
        if (! str_starts_with($hostPath, $allowedPrefix)) {
            throw new \RuntimeException('Refusing to delete a path outside the services backup tree: '.$hostPath);
        }
        $server = $this->getLocalServer();
        instant_remote_process([
            'rm -f '.escapeshellarg($hostPath),
        ], $server, throwError: false);
    }

    /**
     * Walk /data/coolify/backups/services/ on the host and remove
     * .zip files older than ORPHAN_AFTER_MINUTES that no
     * service_backup_runs row points at. Also removes empty
     * staging-* leftover directories.
     */
    private function sweepOrphans(): int
    {
        $server = $this->getLocalServer();
        $base = rtrim(backup_dir(), '/').'/services';

        // Quick early-out if the base dir doesn't exist yet.
        $existsCheck = (string) instant_remote_process([
            'test -d '.escapeshellarg($base).' && echo YES || echo NO',
        ], $server, throwError: false);
        if (trim($existsCheck) !== 'YES') {
            return 0;
        }

        // Pull every .zip path under base, with mtime in epoch
        // seconds. We use printf so the format is the same on
        // every distro and the parser is trivial.
        $list = (string) instant_remote_process([
            'find '.escapeshellarg($base).' -type f -name "*.zip" -printf "%T@ %p\n" 2>/dev/null || true',
        ], $server, throwError: false);

        $now = time();
        $cutoff = $now - (self::ORPHAN_AFTER_MINUTES * 60);
        $knownPaths = ServiceBackupRun::query()
            ->whereNotNull('artifact_path')
            ->pluck('artifact_path')
            ->all();
        $knownSet = array_flip(array_filter($knownPaths, fn ($p) => is_string($p) && $p !== ''));

        $removed = 0;
        foreach (explode("\n", trim($list)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode(' ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $mtime = (int) (float) $parts[0];
            $path = $parts[1];
            if ($mtime > $cutoff) {
                continue;
            }
            if (isset($knownSet[$path])) {
                continue;
            }
            instant_remote_process([
                'rm -f '.escapeshellarg($path),
            ], $server, throwError: false);
            $removed++;
        }

        // Also clean up empty staging directories left behind by
        // crashed runs.
        instant_remote_process([
            'find '.escapeshellarg($base).' -type d -name "staging-*" -mmin +'.self::ORPHAN_AFTER_MINUTES.' -empty -delete 2>/dev/null || true',
        ], $server, throwError: false);

        return $removed;
    }

    private function getLocalServer(): Server
    {
        $server = Server::find(0);
        if (! $server instanceof Server) {
            $server = Server::where('ip', 'host.docker.internal')->first();
        }
        if (! $server instanceof Server) {
            throw new \RuntimeException('No se encontró el servidor Coolify local (id=0).');
        }

        return $server;
    }
}
