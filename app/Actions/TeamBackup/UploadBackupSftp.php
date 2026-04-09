<?php

namespace App\Actions\TeamBackup;

use App\Models\Team;
use App\Models\TeamBackupRun;
use App\Models\TeamBackupSetting;
use Lorisleiva\Actions\Concerns\AsAction;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * Uploads the tarball produced by RunLocalBackup to the external
 * server configured under "Backup Servidor Hawkins" via SFTP.
 *
 * Uses phpseclib 3 (already a dependency of this fork) so there's
 * no native ssh2 extension requirement. Supports two auth methods:
 *   - password: straight user + pass
 *   - key:      RSA/ED25519 private key, optionally with passphrase
 *
 * Retention is applied on the remote side at the end: lists the
 * remote directory, sorts by name descending (the filenames carry
 * the timestamp so this is correct), and deletes everything past
 * the configured retention count.
 *
 * The source run row carries the artifact path of a previously
 * completed local run (the job chain passes it through via
 * `stats_json.source_artifact_path` when scheduling the sftp
 * step).
 */
class UploadBackupSftp
{
    use AsAction;

    public function handle(TeamBackupRun $run, string $sourceTarball): TeamBackupRun
    {
        $team = Team::findOrFail($run->team_id);
        $settings = TeamBackupSetting::forTeam($team->id);

        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'last_message' => 'Conectando por SFTP…',
        ]);

        if (! $settings->sftp_enabled) {
            $run->update([
                'status' => 'skipped',
                'finished_at' => now(),
                'last_message' => 'SFTP deshabilitado en ajustes.',
            ]);

            return $run->fresh();
        }

        if (! is_file($sourceTarball)) {
            throw new \RuntimeException("No existe el tarball origen: {$sourceTarball}");
        }

        $host = (string) $settings->sftp_host;
        $port = (int) ($settings->sftp_port ?: 22);
        $user = (string) $settings->sftp_username;
        $remoteDir = rtrim((string) $settings->sftp_remote_path, '/');
        if ($host === '' || $user === '' || $remoteDir === '') {
            throw new \RuntimeException('Configuración SFTP incompleta (host, usuario o ruta remota vacíos).');
        }

        try {
            $sftp = new SFTP($host, $port, 20);

            if ($settings->sftp_auth_method === 'key') {
                $rawKey = (string) $settings->sftp_private_key;
                if ($rawKey === '') {
                    throw new \RuntimeException('Método "clave SSH" seleccionado pero la clave privada está vacía.');
                }
                $passphrase = (string) $settings->sftp_private_key_passphrase;
                $key = PublicKeyLoader::load($rawKey, $passphrase !== '' ? $passphrase : false);
                if (! $sftp->login($user, $key)) {
                    throw new \RuntimeException('Login por clave SSH rechazado por el servidor.');
                }
            } else {
                $password = (string) $settings->sftp_password;
                if ($password === '') {
                    throw new \RuntimeException('Método "password" seleccionado pero la contraseña está vacía.');
                }
                if (! $sftp->login($user, $password)) {
                    throw new \RuntimeException('Login por contraseña rechazado por el servidor.');
                }
            }

            // Ensure the remote directory exists. SFTP mkdir is
            // per-segment so we walk the path.
            $segments = explode('/', trim($remoteDir, '/'));
            $accum = '';
            foreach ($segments as $seg) {
                if ($seg === '') {
                    continue;
                }
                $accum .= '/'.$seg;
                if (! $sftp->is_dir($accum)) {
                    $sftp->mkdir($accum);
                }
            }

            $basename = basename($sourceTarball);
            $remotePath = $remoteDir.'/'.$basename;

            $uploaded = $sftp->put($remotePath, $sourceTarball, SFTP::SOURCE_LOCAL_FILE);
            if (! $uploaded) {
                throw new \RuntimeException('La subida del archivo falló.');
            }

            // Verify the remote size matches so we don't report
            // success for a truncated upload.
            $remoteSize = $sftp->filesize($remotePath);
            $localSize = filesize($sourceTarball);
            if ($remoteSize === false || $localSize === false || (int) $remoteSize !== (int) $localSize) {
                throw new \RuntimeException("Tamaño remoto ({$remoteSize}) no coincide con el local ({$localSize}).");
            }

            // Remote retention: list remote directory, filter
            // tarballs matching our naming pattern, sort by name
            // desc (timestamp is embedded so this is date-desc),
            // delete everything past the retention count.
            $deleted = [];
            try {
                $entries = $sftp->nlist($remoteDir) ?: [];
                $tarballs = collect($entries)
                    ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'coolify-backup-') && str_ends_with($name, '.tar.gz'))
                    ->sortDesc()
                    ->values()
                    ->all();
                $keep = max(1, (int) $settings->sftp_retention);
                foreach (array_slice($tarballs, $keep) as $old) {
                    if ($sftp->delete($remoteDir.'/'.$old, false)) {
                        $deleted[] = $old;
                    }
                }
            } catch (\Throwable $e) {
                // retention failure is not fatal — the new tarball
                // is already there, just flag in stats.
            }

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'size_bytes' => $localSize ?: null,
                'artifact_path' => "sftp://{$user}@{$host}:{$port}{$remotePath}",
                'last_message' => 'Subida SFTP completada.',
                'stats_json' => [
                    'remote_path' => $remotePath,
                    'deleted_during_retention' => $deleted,
                    'bytes_uploaded' => $localSize,
                ],
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_message' => 'SFTP fallido: '.mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }

        return $run->fresh();
    }
}
