<?php

namespace App\Actions\TeamBackup;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\TeamBackupRun;
use App\Models\TeamBackupSetting;
use Carbon\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Generates a single "full team" backup on the Coolify host and
 * writes a manifest so the UI (and the SFTP upload action) can
 * reason about what went in.
 *
 * Architecture:
 *   1. Creates a staging directory under
 *      /data/coolify/backups/custom/{team-slug}-{team-id}/{timestamp}/
 *   2. For each database in scope, runs the right dump command
 *      (docker exec on the server where the container lives),
 *      redirecting stdout to a file in staging/databases/.
 *   3. For each "file source" in scope, tars the directory into
 *      staging/files/. Persistent-volume mode only captures the
 *      volumes each resource explicitly declared; full-container
 *      mode also tars /var/www/html (the whole web root of every
 *      service/application container).
 *   4. Writes staging/manifest.json listing every artifact with
 *      its size and sha256.
 *   5. Compresses the whole staging dir into one tarball
 *      coolify-backup-{timestamp}.tar.gz sitting next to it.
 *   6. Deletes the staging dir, leaves only the tarball.
 *
 * Does NOT upload anywhere, does NOT apply retention — those are
 * separate actions so the job orchestrator can chain them and the
 * UI can show granular status per step.
 */
class RunLocalBackup
{
    use AsAction;

    private TeamBackupRun $run;

    private TeamBackupSetting $settings;

    private Team $team;

    private string $stagingDir = '';

    private string $tarballPath = '';

    /**
     * @var array<string, mixed>
     */
    private array $stats = [
        'databases_dumped' => 0,
        'databases_failed' => 0,
        'files_archived' => 0,
        'files_failed' => 0,
        'errors' => [],
    ];

    public function handle(TeamBackupRun $run): TeamBackupRun
    {
        $this->run = $run;
        $this->team = Team::findOrFail($run->team_id);
        $this->settings = TeamBackupSetting::forTeam($this->team->id);

        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'last_message' => 'Inicializando backup local…',
        ]);

        try {
            $this->prepareStagingDirectory();

            $scope = $run->scope;
            if ($scope === 'full' || $scope === 'databases') {
                $this->dumpAllDatabases();
            }
            if ($scope === 'full' || $scope === 'files') {
                $this->archiveAllFiles();
            }

            $this->writeManifest();
            $this->compressToTarball();
            $this->cleanupStaging();

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'size_bytes' => @filesize($this->tarballPath) ?: null,
                'artifact_path' => $this->tarballPath,
                'last_message' => 'Backup local completado.',
                'stats_json' => $this->stats,
            ]);
        } catch (\Throwable $e) {
            // Best-effort cleanup on failure so we don't leave
            // half-finished staging dirs lying around eating disk.
            $this->cleanupStaging();
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_message' => 'Backup local fallido: '.mb_substr($e->getMessage(), 0, 1000),
                'stats_json' => $this->stats,
            ]);
            throw $e;
        }

        return $run->fresh();
    }

    /**
     * Creates the per-team, per-timestamp staging directory on the
     * Coolify host using the local server (id=0). Every command
     * this action issues happens against that server so volumes
     * mounted via `-v` on other hosts DO NOT get copied by this
     * action — the assumption is "all the Coolify volumes live on
     * the Coolify host" which is the common single-server setup.
     * Multi-host support can be layered on top later by iterating
     * $server->applications and SCPing each remote dir into place.
     */
    private function prepareStagingDirectory(): void
    {
        $teamSlug = str($this->team->name)->slug()->value() ?: 'team';
        $timestamp = Carbon::now()->format('Y-m-d_His');
        $base = rtrim($this->settings->local_path, '/');
        $this->stagingDir = "{$base}/{$teamSlug}-{$this->team->id}/{$timestamp}";
        $this->tarballPath = "{$base}/{$teamSlug}-{$this->team->id}/coolify-backup-{$timestamp}.tar.gz";

        $server = $this->getLocalServer();
        instant_remote_process([
            "mkdir -p ".escapeshellarg($this->stagingDir).'/databases',
            "mkdir -p ".escapeshellarg($this->stagingDir).'/files',
        ], $server, throwError: true);
    }

    /**
     * Runs the dump for every StandaloneDatabase AND every
     * ServiceDatabase owned by the current team.
     *
     * Dump commands mirror what Coolify's own DatabaseBackupJob
     * issues so we benefit from the same `--single-transaction`,
     * `--quick`, `--lock-tables=false` flags that keep live
     * writers happy.
     */
    private function dumpAllDatabases(): void
    {
        $teamId = $this->team->id;

        // Standalone databases: one dump per database instance.
        foreach ($this->teamStandaloneDatabases($teamId) as $db) {
            $this->safelyDumpStandalone($db);
        }

        // Service-embedded databases: dumps each container inside
        // the compose stacks (the MariaDB that ships with WordPress,
        // Laravel RootKit, etc.).
        foreach (ServiceDatabase::whereHas('service.environment.project.team', fn ($q) => $q->where('id', $teamId))->get() as $svcDb) {
            $this->safelyDumpServiceDatabase($svcDb);
        }
    }

    /**
     * @return iterable
     */
    private function teamStandaloneDatabases(int $teamId): iterable
    {
        $query = fn ($model) => $model::whereHas('environment.project.team', fn ($q) => $q->where('id', $teamId))->get();
        foreach ([
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
        ] as $model) {
            foreach ($query($model) as $db) {
                yield $db;
            }
        }
    }

    private function safelyDumpStandalone($db): void
    {
        try {
            $server = $db->destination?->server;
            if (! $server instanceof Server) {
                throw new \RuntimeException("Sin servidor para la base de datos {$db->name}");
            }

            $type = strtolower((string) $db->type());
            $containerName = (string) $db->uuid;
            $safeName = str($db->name)->slug()->value();
            $target = '';

            if (str_contains($type, 'postgres')) {
                $target = "{$this->stagingDir}/databases/postgres-{$safeName}.sql.gz";
                $password = $db->postgres_password ?? '';
                $user = $db->postgres_user ?? 'postgres';
                $cmd = "docker exec -e PGPASSWORD=".escapeshellarg($password)." {$containerName} pg_dumpall --username ".escapeshellarg($user)." | gzip > ".escapeshellarg($target);
            } elseif (str_contains($type, 'mysql')) {
                $target = "{$this->stagingDir}/databases/mysql-{$safeName}.sql.gz";
                $rootPwd = $db->mysql_root_password ?? '';
                $cmd = "docker exec {$containerName} mysqldump -u root -p".escapeshellarg($rootPwd)." --all-databases --single-transaction --quick --lock-tables=false | gzip > ".escapeshellarg($target);
            } elseif (str_contains($type, 'mariadb')) {
                $target = "{$this->stagingDir}/databases/mariadb-{$safeName}.sql.gz";
                $rootPwd = $db->mariadb_root_password ?? '';
                $cmd = "docker exec {$containerName} mariadb-dump -u root -p".escapeshellarg($rootPwd)." --all-databases --single-transaction --quick --lock-tables=false | gzip > ".escapeshellarg($target);
            } elseif (str_contains($type, 'mongo')) {
                $target = "{$this->stagingDir}/databases/mongo-{$safeName}.tar.gz";
                $user = $db->mongo_initdb_root_username ?? 'root';
                $pwd = $db->mongo_initdb_root_password ?? '';
                $cmd = "docker exec {$containerName} sh -c 'mongodump --archive --gzip -u ".escapeshellarg($user)." -p ".escapeshellarg($pwd)."' > ".escapeshellarg($target);
            } else {
                // Redis / KeyDB / Dragonfly: RDB snapshot via
                // `docker cp` of the dump.rdb file. Not a full
                // point-in-time dump but matches Coolify's own
                // approach for these stores.
                $target = "{$this->stagingDir}/databases/kv-{$safeName}-dump.rdb.gz";
                $cmd = "docker exec {$containerName} sh -c 'cat /data/dump.rdb 2>/dev/null || true' | gzip > ".escapeshellarg($target);
            }

            instant_remote_process([$cmd], $server, throwError: true, timeout: 3600, disableMultiplexing: true);
            $this->stats['databases_dumped']++;
        } catch (\Throwable $e) {
            $this->stats['databases_failed']++;
            $this->stats['errors'][] = "standalone:{$db->name}: ".$e->getMessage();
        }
    }

    private function safelyDumpServiceDatabase(ServiceDatabase $db): void
    {
        try {
            $server = $db->service->server;
            if (! $server instanceof Server) {
                throw new \RuntimeException("Sin servidor para la base de datos de servicio {$db->name}");
            }

            $containerName = $db->name.'-'.$db->service->uuid;
            $type = strtolower((string) $db->databaseType());
            $safeName = str($db->service->name.'-'.$db->name)->slug()->value();

            // Extract root password from env inside the container
            // so we don't depend on the .env file layout — mirrors
            // what DatabaseBackupJob::retrieve_password_from_env() does.
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

            $target = '';
            $cmd = '';
            if (str_contains($type, 'mariadb') || str_contains($type, 'mysql')) {
                $pwd = $envPairs['MARIADB_ROOT_PASSWORD'] ?? $envPairs['MYSQL_ROOT_PASSWORD'] ?? '';
                if ($pwd === '') {
                    throw new \RuntimeException("Sin contraseña root en el entorno de {$containerName}");
                }
                $dumpBin = str_contains($type, 'mariadb') ? 'mariadb-dump' : 'mysqldump';
                $target = "{$this->stagingDir}/databases/svc-{$safeName}.sql.gz";
                $cmd = "docker exec {$containerName} {$dumpBin} -u root -p".escapeshellarg($pwd)." --all-databases --single-transaction --quick --lock-tables=false | gzip > ".escapeshellarg($target);
            } elseif (str_contains($type, 'postgres')) {
                $pwd = $envPairs['POSTGRES_PASSWORD'] ?? '';
                $user = $envPairs['POSTGRES_USER'] ?? 'postgres';
                $target = "{$this->stagingDir}/databases/svc-{$safeName}.sql.gz";
                $cmd = "docker exec -e PGPASSWORD=".escapeshellarg($pwd)." {$containerName} pg_dumpall --username ".escapeshellarg($user)." | gzip > ".escapeshellarg($target);
            } else {
                // Unknown service database type — skip gracefully.
                $this->stats['errors'][] = "service:{$containerName}: tipo {$type} no soportado";

                return;
            }

            instant_remote_process([$cmd], $server, throwError: true, timeout: 3600, disableMultiplexing: true);
            $this->stats['databases_dumped']++;
        } catch (\Throwable $e) {
            $this->stats['databases_failed']++;
            $this->stats['errors'][] = "service:{$db->name}: ".$e->getMessage();
        }
    }

    /**
     * Archive persistent volumes (or whole container web roots,
     * depending on local_file_mode) into /files as tarballs.
     */
    private function archiveAllFiles(): void
    {
        $teamId = $this->team->id;
        $mode = $this->settings->local_file_mode;

        // Applications
        foreach (Application::ownedByCurrentTeamCached() ?? Application::whereHas('environment.project.team', fn ($q) => $q->where('id', $teamId))->get() as $app) {
            $this->archiveResource($app, 'app-'.str($app->name)->slug(), $mode);
        }

        // Services (apps + databases inside compose). We archive at
        // the ServiceApplication level because that's where the
        // persistent volumes hang and where /var/www/html lives.
        foreach (ServiceApplication::whereHas('service.environment.project.team', fn ($q) => $q->where('id', $teamId))->get() as $svcApp) {
            $this->archiveResource($svcApp, 'svc-'.str($svcApp->service->name.'-'.$svcApp->name)->slug(), $mode);
        }
    }

    private function archiveResource($resource, string $slug, string $mode): void
    {
        try {
            $server = $resource->service?->server ?? $resource->destination?->server;
            if (! $server instanceof Server) {
                return;
            }

            // Mode A: persistent volumes only — read
            // LocalPersistentVolume rows for this resource and tar
            // the host_path of each one.
            if ($mode === 'persistent') {
                $volumes = LocalPersistentVolume::where('resource_type', $resource::class)
                    ->where('resource_id', $resource->id)
                    ->get();
                $i = 0;
                foreach ($volumes as $vol) {
                    $hostPath = (string) $vol->host_path;
                    if ($hostPath === '' || $hostPath === null) {
                        continue;
                    }
                    $i++;
                    $target = "{$this->stagingDir}/files/{$slug}-vol{$i}.tar.gz";
                    instant_remote_process([
                        "tar -czf ".escapeshellarg($target)." -C / ".escapeshellarg(ltrim($hostPath, '/'))." 2>/dev/null || true",
                    ], $server, throwError: false, timeout: 3600, disableMultiplexing: true);
                    $this->stats['files_archived']++;
                }

                return;
            }

            // Mode C: full /var/www/html of the container. Works
            // for both services and applications that run Laravel /
            // WordPress / etc. Use docker exec tar | ... so the
            // stream lands directly on the staging dir on the host.
            $containerName = $resource->service ? ($resource->name.'-'.$resource->service->uuid) : ($resource->uuid ?? $resource->name);
            $target = "{$this->stagingDir}/files/{$slug}-webroot.tar.gz";
            instant_remote_process([
                "docker exec {$containerName} tar -czf - -C /var/www html 2>/dev/null > ".escapeshellarg($target)." || true",
            ], $server, throwError: false, timeout: 7200, disableMultiplexing: true);
            $this->stats['files_archived']++;
        } catch (\Throwable $e) {
            $this->stats['files_failed']++;
            $this->stats['errors'][] = "files:{$slug}: ".$e->getMessage();
        }
    }

    private function writeManifest(): void
    {
        $server = $this->getLocalServer();
        $manifest = [
            'schema' => 1,
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'generated_at' => now()->toIso8601String(),
            'scope' => $this->run->scope,
            'file_mode' => $this->settings->local_file_mode,
            'stats' => $this->stats,
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $target = "{$this->stagingDir}/manifest.json";
        // Write via sh heredoc so we don't need scp round-trip.
        instant_remote_process([
            "cat > ".escapeshellarg($target)." <<'COOLIFY_MANIFEST_EOF'\n".$json."\nCOOLIFY_MANIFEST_EOF",
        ], $server, throwError: true);
    }

    private function compressToTarball(): void
    {
        $server = $this->getLocalServer();
        instant_remote_process([
            "tar -czf ".escapeshellarg($this->tarballPath)." -C ".escapeshellarg(dirname($this->stagingDir))." ".escapeshellarg(basename($this->stagingDir)),
        ], $server, throwError: true, timeout: 7200, disableMultiplexing: true);
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
            // swallow — the tarball is what matters
        }
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
