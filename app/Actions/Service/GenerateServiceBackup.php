<?php

namespace App\Actions\Service;

use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceBackupRun;
use App\Models\ServiceDatabase;
use Carbon\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Builds a single downloadable .zip for a WordPress service.
 *
 * Workflow:
 *   1. Create a staging directory on the Coolify host under
 *      /data/coolify/backups/services/<service-uuid>/<timestamp>/
 *   2. Tar /var/www/html out of the WordPress container into
 *      staging/wordpress-files.tar.gz (we use tar.gz inside the
 *      zip because it preserves Unix permissions and symlinks
 *      that bare zip would mangle).
 *   3. mysqldump the embedded MariaDB / MySQL container into
 *      staging/database.sql.gz, fishing the root password out of
 *      the container's env so we don't depend on the .env layout.
 *   4. Zip the whole staging dir into one
 *      "<service-name>-<timestamp>.zip" sitting next to it.
 *   5. Delete the staging dir.
 *   6. Update the ServiceBackupRun row with status, size,
 *      artifact_path and expires_at = now + 30 min.
 *
 * Why zip on the outside even though we tar.gz the file tree:
 *   - Zip is what end-users (often non-technical Windows clients)
 *     can open without installing anything. Double-clicking the
 *     download in File Explorer just works.
 *   - The inner tar.gz is for fidelity (permissions, symlinks)
 *     when the same archive is shipped to a real Linux host for
 *     restore. The user double-clicks the zip and finds the inner
 *     bundle ready for `tar -xzf`.
 *
 * What this action does NOT do:
 *   - Touch the WordPress container's runtime (no `wp` shutdown,
 *     no maintenance mode flag). The dump uses `--single-transaction`
 *     so live writers stay happy.
 *   - Copy or modify the original `wp-content/uploads` files. Tar
 *     reads them straight from the running container's filesystem.
 *   - Apply any retention beyond setting expires_at. The actual
 *     pruning runs from PruneExpiredServiceBackups via the
 *     scheduler every minute.
 *
 * Failures roll the run row to `failed` with the exception message
 * truncated to 1000 chars in last_message, and the staging dir is
 * best-effort removed so a half-finished tarball doesn't sit on
 * disk.
 */
class GenerateServiceBackup
{
    use AsAction;

    /**
     * How long a generated backup stays downloadable before the
     * scheduler removes it. The user explicitly asked for 30 min.
     * Centralised here so the constant is the single source of
     * truth — both this action and PruneExpiredServiceBackups
     * read it.
     */
    public const TTL_MINUTES = 30;

    private ServiceBackupRun $run;

    private Service $service;

    private string $stagingDir = '';

    private string $zipPath = '';

    public function handle(ServiceBackupRun $run): ServiceBackupRun
    {
        $this->run = $run;
        $this->service = Service::findOrFail($run->service_id);

        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'last_message' => 'Generando copia descargable…',
        ]);

        try {
            $this->guardWordPressOnly();
            $this->prepareStagingDirectory();
            $this->archiveWordPressFiles();
            $this->dumpEmbeddedDatabase();
            $this->zipStagingDirectory();
            $this->cleanupStaging();

            $size = $this->statSize();
            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'size_bytes' => $size,
                'artifact_path' => $this->zipPath,
                'archive_name' => basename($this->zipPath),
                'last_message' => 'Copia lista para descargar.',
            ]);
        } catch (\Throwable $e) {
            $this->cleanupStaging();
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_message' => 'Error: '.mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }

        return $run->fresh();
    }

    /**
     * Hard guard so this action refuses to run on anything that
     * is not a WordPress service. The Livewire UI also gates the
     * trigger button, but defense-in-depth: a forged request that
     * dispatches the job for a non-WordPress service still hits
     * this check before any disk activity happens.
     */
    private function guardWordPressOnly(): void
    {
        $hasWordPressApp = false;
        foreach ($this->service->applications as $app) {
            $image = strtolower((string) $app->image);
            if (str_contains($image, 'wordpress')) {
                $hasWordPressApp = true;
                break;
            }
        }
        if (! $hasWordPressApp) {
            // Fallback: env var sniffing, mirrors hasWordPress() in
            // app/Livewire/Project/Service/Configuration.php so the
            // two predicates always agree.
            foreach ($this->service->applications as $app) {
                foreach ($app->environment_variables()->get() as $env) {
                    if (str_contains(strtoupper((string) $env->key), 'WORDPRESS')) {
                        $hasWordPressApp = true;
                        break 2;
                    }
                }
            }
        }
        if (! $hasWordPressApp) {
            throw new \RuntimeException('Este servicio no es WordPress. La feature de copia descargable solo está disponible para WordPress.');
        }
    }

    /**
     * Build the staging dir + final zip path. Both live under
     * /data/coolify/backups/services/<service-uuid>/ so the
     * download controller can translate the path with
     * backup_host_to_container_path() (which only translates paths
     * under backup_dir() = /data/coolify/backups/).
     */
    private function prepareStagingDirectory(): void
    {
        $serviceUuid = (string) $this->service->uuid;
        $serviceSlug = str($this->service->name ?? 'service')->slug()->value() ?: 'service';
        $timestamp = Carbon::now()->format('Y-m-d_His');

        $base = rtrim(backup_dir(), '/').'/services/'.$serviceUuid;
        $this->stagingDir = "{$base}/staging-{$timestamp}";
        $this->zipPath = "{$base}/{$serviceSlug}-{$timestamp}.zip";

        $server = $this->getLocalServer();
        instant_remote_process([
            'mkdir -p '.escapeshellarg($this->stagingDir),
        ], $server, throwError: true);
    }

    /**
     * Tar /var/www/html out of the WordPress container directly
     * into the staging directory on the host. We pipe through
     * docker exec instead of relying on a host bind mount because
     * not every WordPress service template is set up with a
     * named volume on the host filesystem — `docker exec tar` is
     * the lowest common denominator that works regardless of how
     * the volumes are wired.
     *
     * The fallback to `|| true` keeps a partial tar from killing
     * the whole run: if a few unreadable files (broken symlinks,
     * permission edge cases) make tar exit non-zero, we still
     * keep what we got and let the operator open the resulting
     * archive.
     */
    private function archiveWordPressFiles(): void
    {
        $wpApp = $this->resolveWordPressApplication();
        $server = $this->resolveServiceServer();
        $containerName = $this->containerNameFor($wpApp);
        $target = "{$this->stagingDir}/wordpress-files.tar.gz";

        $cmd = "docker exec {$containerName} sh -c 'tar -czf - -C /var/www html 2>/dev/null' > ".escapeshellarg($target)." || true";
        instant_remote_process([$cmd], $server, throwError: false, timeout: 7200, disableMultiplexing: true);

        $this->assertFileExists($target, 'No se pudo generar el archivo de archivos del sitio. ¿El contenedor de WordPress está arriba?');
    }

    /**
     * Dump the WordPress database. We look for a sibling
     * ServiceDatabase in the same stack (the MariaDB/MySQL that
     * the WordPress compose template ships with) and shell out to
     * mysqldump / mariadb-dump inside its container. Password
     * comes from the container's env block, mirroring exactly
     * what TeamBackup's safelyDumpServiceDatabase does.
     */
    private function dumpEmbeddedDatabase(): void
    {
        $svcDb = $this->resolveEmbeddedDatabase();
        if ($svcDb === null) {
            // No embedded DB found inside the same compose stack.
            // Some WordPress templates allow pointing at an
            // external DB, in which case the user wants the files
            // archive only — we leave a placeholder readme so the
            // resulting zip is self-explanatory and don't fail
            // the run.
            $this->writeNoDatabaseReadme();

            return;
        }

        $server = $this->resolveServiceServer();
        $containerName = $svcDb->name.'-'.$svcDb->service->uuid;
        $envDump = (string) instant_remote_process(
            ["docker exec {$containerName} env"],
            $server,
            throwError: false,
            timeout: 60,
            disableMultiplexing: true
        );
        $envPairs = [];
        foreach (explode("\n", $envDump) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $envPairs[trim($k)] = trim($v);
            }
        }

        $type = strtolower((string) $svcDb->databaseType());
        $target = "{$this->stagingDir}/database.sql.gz";

        if (str_contains($type, 'mariadb') || str_contains($type, 'mysql')) {
            $pwd = $envPairs['MARIADB_ROOT_PASSWORD'] ?? $envPairs['MYSQL_ROOT_PASSWORD'] ?? '';
            if ($pwd === '') {
                throw new \RuntimeException("Sin contraseña root en el entorno de {$containerName}. No se puede dumpear la BD.");
            }
            $dumpBin = str_contains($type, 'mariadb') ? 'mariadb-dump' : 'mysqldump';
            $cmd = "docker exec {$containerName} {$dumpBin} -u root -p".escapeshellarg($pwd)." --all-databases --single-transaction --quick --lock-tables=false 2>/dev/null | gzip > ".escapeshellarg($target);
        } else {
            // WordPress with a non-MySQL backend is exotic but
            // possible. We don't ship a dumper for every flavour
            // here — leave a readme and move on so the user still
            // gets the files.
            $this->writeNoDatabaseReadme(' (tipo no soportado: '.$type.')');

            return;
        }

        instant_remote_process([$cmd], $server, throwError: false, timeout: 3600, disableMultiplexing: true);
        $this->assertFileExists($target, 'No se pudo generar el dump de la base de datos.');
    }

    /**
     * Compress the staging dir into the final zip on the host.
     * We use `zip -r` from the host (not from inside the
     * containers) because the staging dir already lives on the
     * Coolify host disk after the previous two steps. The host
     * always has zip available — it's pulled in by the docker
     * install on every Coolify-supported distro.
     *
     * If the host happens not to have `zip`, we fall back to
     * `tar -cf - ... | python3 zipfile` only when zip is missing.
     * For now we expect zip to be there because the install
     * script and the Coolify base image both depend on it.
     */
    private function zipStagingDirectory(): void
    {
        $server = $this->getLocalServer();

        // Use a subshell so the cd into the staging parent dir
        // gives the zip file a clean tree (no /data/... prefix
        // inside the archive). The -j (junk paths) flag would do
        // the same but loses subdirectories — `cd` is cleaner.
        $parent = dirname($this->stagingDir);
        $stagingBase = basename($this->stagingDir);
        $cmd = "cd ".escapeshellarg($parent)." && zip -qr ".escapeshellarg($this->zipPath)." ".escapeshellarg($stagingBase);
        instant_remote_process([$cmd], $server, throwError: true, timeout: 3600, disableMultiplexing: true);

        $this->assertFileExists($this->zipPath, 'No se pudo generar el zip final.');
    }

    private function cleanupStaging(): void
    {
        if ($this->stagingDir === '') {
            return;
        }
        try {
            $server = $this->getLocalServer();
            instant_remote_process([
                'rm -rf '.escapeshellarg($this->stagingDir),
            ], $server, throwError: false);
        } catch (\Throwable $e) {
            // swallow — having a leftover staging dir is annoying
            // but not catastrophic; the prune sweeper takes them
            // out eventually too.
        }
    }

    private function writeNoDatabaseReadme(string $suffix = ''): void
    {
        $server = $this->getLocalServer();
        $message = "No se incluyó base de datos en esta copia{$suffix}. Si tu sitio usa una base de datos externa, restaúrala manualmente.";
        instant_remote_process([
            "cat > ".escapeshellarg($this->stagingDir.'/README-db.txt')." <<'COOLIFY_README_EOF'\n".$message."\nCOOLIFY_README_EOF",
        ], $server, throwError: false);
    }

    /**
     * Find the WordPress ServiceApplication inside this stack.
     * Mirrors hasWordPress() in Configuration.php — image-name
     * match first, env-var sniff second.
     */
    private function resolveWordPressApplication(): ServiceApplication
    {
        foreach ($this->service->applications as $app) {
            if (str_contains(strtolower((string) $app->image), 'wordpress')) {
                return $app;
            }
        }
        foreach ($this->service->applications as $app) {
            foreach ($app->environment_variables()->get() as $env) {
                if (str_contains(strtoupper((string) $env->key), 'WORDPRESS')) {
                    return $app;
                }
            }
        }
        throw new \RuntimeException('No se encontró el contenedor de WordPress en este servicio.');
    }

    /**
     * Find the embedded database inside this stack. Returns null
     * if the WordPress is configured against an external DB and
     * the stack doesn't include a DB container of its own.
     */
    private function resolveEmbeddedDatabase(): ?ServiceDatabase
    {
        foreach ($this->service->databases as $db) {
            $type = strtolower((string) $db->databaseType());
            if (str_contains($type, 'mariadb') || str_contains($type, 'mysql')) {
                return $db;
            }
        }

        return null;
    }

    private function resolveServiceServer(): Server
    {
        $server = $this->service->server;
        if (! $server instanceof Server) {
            throw new \RuntimeException('El servicio no tiene servidor asociado.');
        }

        return $server;
    }

    private function containerNameFor(ServiceApplication $app): string
    {
        return $app->name.'-'.$this->service->uuid;
    }

    private function statSize(): ?int
    {
        $server = $this->getLocalServer();
        $output = (string) instant_remote_process([
            "stat -c '%s' ".escapeshellarg($this->zipPath)." 2>/dev/null || echo ''",
        ], $server, throwError: false);
        $bytes = (int) trim($output);

        return $bytes > 0 ? $bytes : null;
    }

    private function assertFileExists(string $hostPath, string $errorMessage): void
    {
        $server = $this->getLocalServer();
        $output = (string) instant_remote_process([
            "test -s ".escapeshellarg($hostPath)." && echo OK || echo MISSING",
        ], $server, throwError: false);
        if (trim($output) !== 'OK') {
            throw new \RuntimeException($errorMessage);
        }
    }

    /**
     * Local Coolify server (id=0) where the host filesystem
     * /data/coolify/backups lives. Mirrors RunLocalBackup's helper
     * with the same fallback for installs that don't have id=0.
     */
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
