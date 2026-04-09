<?php

namespace App\Jobs;

use App\Actions\TeamBackup\PruneLocalBackups;
use App\Actions\TeamBackup\RunLocalBackup;
use App\Actions\TeamBackup\UploadBackupSftp;
use App\Models\Team;
use App\Models\TeamBackupRun;
use App\Models\TeamBackupSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Orchestrator job for the team-wide "Backups" feature.
 *
 * Supports three execution modes:
 *   - both:       local backup then (on success) SFTP upload.
 *   - local-only: just the local backup, no SFTP attempt.
 *   - sftp-only:  re-upload the most recent completed local tarball
 *                 to SFTP without redoing the dumps. Useful when a
 *                 previous SFTP leg failed and the user wants to
 *                 retry just the upload part.
 *
 * Each step runs with an internal 1-retry loop: on the first
 * failure we wait a few seconds and try again; only if the retry
 * also fails do we record the run as failed and email the team.
 * The retry lives INSIDE this job (not at Laravel's queue level)
 * so a local-success + sftp-failure doesn't redo the local dumps
 * on retry.
 *
 * `batch_uuid` groups all runs produced by a single dispatch so the
 * UI can render them as one "run".
 */
class RunTeamBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Up to 2h for each step (local + sftp) with a retry buffer.
     * Per-step retry handles transient failures, so we keep
     * Laravel's tries=1 — a whole-job retry would re-dump every
     * database on a simple SFTP glitch, which is wasteful.
     */
    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public string $scope = 'full',
        public string $trigger = 'manual',
        public string $mode = 'both',
    ) {}

    public function handle(): void
    {
        $team = Team::find($this->teamId);
        if (! $team) {
            return;
        }
        $settings = TeamBackupSetting::forTeam($this->teamId);

        if (! $settings->local_enabled && ! $settings->sftp_enabled) {
            return;
        }

        $batchUuid = (string) Str::uuid();

        if ($this->mode === 'sftp-only') {
            $this->runSftpOnly($team, $settings, $batchUuid);

            return;
        }

        // ------------------------------------------------------------
        // STEP 1 — Local backup
        // ------------------------------------------------------------
        $localTarball = null;
        if ($settings->local_enabled) {
            $localRun = TeamBackupRun::create([
                'team_id' => $this->teamId,
                'batch_uuid' => $batchUuid,
                'destination' => 'local',
                'scope' => $this->scope,
                'trigger' => $this->trigger,
                'status' => 'pending',
                'last_message' => 'En cola…',
            ]);

            try {
                $this->runWithRetry(
                    fn () => RunLocalBackup::run($localRun),
                    label: 'backup local',
                );
                $localRun->refresh();
                if ($localRun->status === 'completed') {
                    $localTarball = (string) $localRun->artifact_path;
                    PruneLocalBackups::run($this->teamId);
                }
            } catch (\Throwable $e) {
                $this->notifyFailure($team, $settings, 'Backup Local', $e, $batchUuid);
            }
        }

        // ------------------------------------------------------------
        // STEP 2 — SFTP upload (only if mode=both AND local succeeded)
        // ------------------------------------------------------------
        if ($this->mode === 'local-only') {
            return;
        }
        if (! $settings->sftp_enabled) {
            return;
        }
        if ($localTarball === null || ! is_file($localTarball)) {
            TeamBackupRun::create([
                'team_id' => $this->teamId,
                'batch_uuid' => $batchUuid,
                'destination' => 'sftp',
                'scope' => $this->scope,
                'trigger' => $this->trigger,
                'status' => 'skipped',
                'started_at' => now(),
                'finished_at' => now(),
                'last_message' => 'SFTP saltado: no hay tarball local de origen (el paso local falló o no se ejecutó).',
            ]);

            return;
        }

        $sftpRun = TeamBackupRun::create([
            'team_id' => $this->teamId,
            'batch_uuid' => $batchUuid,
            'destination' => 'sftp',
            'scope' => $this->scope,
            'trigger' => $this->trigger,
            'status' => 'pending',
            'last_message' => 'Esperando para subir por SFTP…',
        ]);

        try {
            $this->runWithRetry(
                fn () => UploadBackupSftp::run($sftpRun, $localTarball),
                label: 'subida SFTP',
            );
        } catch (\Throwable $e) {
            $this->notifyFailure($team, $settings, 'Backup SFTP', $e, $batchUuid);
        }
    }

    /**
     * "Solo SFTP" mode: finds the last completed local run for this
     * team, verifies its tarball is still on disk, and uploads it.
     * If there's no local tarball to re-upload we record a skipped
     * run row and send a failure email explaining why.
     */
    private function runSftpOnly(Team $team, TeamBackupSetting $settings, string $batchUuid): void
    {
        if (! $settings->sftp_enabled) {
            $sftpRun = TeamBackupRun::create([
                'team_id' => $this->teamId,
                'batch_uuid' => $batchUuid,
                'destination' => 'sftp',
                'scope' => $this->scope,
                'trigger' => $this->trigger,
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'last_message' => 'El SFTP está desactivado en los ajustes.',
            ]);
            $this->notifyFailure(
                $team,
                $settings,
                'Backup SFTP',
                new \RuntimeException('Configuración SFTP incompleta: SFTP está desactivado en la pestaña de ajustes.'),
                $batchUuid
            );

            return;
        }

        $lastLocal = TeamBackupRun::query()
            ->where('team_id', $this->teamId)
            ->where('destination', 'local')
            ->where('status', 'completed')
            ->whereNotNull('artifact_path')
            ->latest('created_at')
            ->first();

        if (! $lastLocal || ! is_file((string) $lastLocal->artifact_path)) {
            TeamBackupRun::create([
                'team_id' => $this->teamId,
                'batch_uuid' => $batchUuid,
                'destination' => 'sftp',
                'scope' => $this->scope,
                'trigger' => $this->trigger,
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'last_message' => 'No hay tarball local previo. Ejecuta primero un backup local.',
            ]);
            $this->notifyFailure(
                $team,
                $settings,
                'Backup SFTP',
                new \RuntimeException('No existe el tarball origen: el modo "Solo SFTP" necesita un backup local previo y no se encontró ninguno completado en disco.'),
                $batchUuid
            );

            return;
        }

        $sftpRun = TeamBackupRun::create([
            'team_id' => $this->teamId,
            'batch_uuid' => $batchUuid,
            'destination' => 'sftp',
            'scope' => $this->scope,
            'trigger' => $this->trigger,
            'status' => 'pending',
            'last_message' => 'Re-subiendo el último tarball local por SFTP…',
        ]);

        try {
            $this->runWithRetry(
                fn () => UploadBackupSftp::run($sftpRun, (string) $lastLocal->artifact_path),
                label: 'subida SFTP',
            );
        } catch (\Throwable $e) {
            $this->notifyFailure($team, $settings, 'Backup SFTP', $e, $batchUuid);
        }
    }

    /**
     * Runs a step with one automatic retry. Sleeps a few seconds
     * between attempts so transient issues (SSH connection reset,
     * docker daemon momentary overload) clear on their own. If
     * BOTH attempts fail, re-throws the last exception for the
     * caller to notify the team.
     */
    private function runWithRetry(callable $fn, string $label): void
    {
        $attempts = 2;
        $delaySeconds = 8;
        $lastException = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $fn();

                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($i < $attempts) {
                    sleep($delaySeconds);
                }
            }
        }

        if ($lastException) {
            throw $lastException;
        }
    }

    /**
     * Classifies an exception message into a human-readable reason
     * + a suggested fix. Matches on strings that RunLocalBackup
     * and UploadBackupSftp actually throw, plus the common system
     * errors surfaced by tar, docker exec and phpseclib.
     *
     * @return array{category:string,hint:string}
     */
    private function categorizeFailure(string $exceptionMessage): array
    {
        $msg = mb_strtolower($exceptionMessage);

        // --- SFTP login / auth ---------------------------------------
        if (str_contains($msg, 'login') && (str_contains($msg, 'rechazado') || str_contains($msg, 'rejected'))) {
            return [
                'category' => 'Login SFTP rechazado por el servidor',
                'hint' => 'Revisa usuario y contraseña (o clave SSH privada) en la pestaña "Backup Servidor Hawkins". Verifica que el usuario existe en el servidor destino y que las credenciales son correctas.',
            ];
        }

        // --- SFTP network / connectivity -----------------------------
        if (
            str_contains($msg, 'connection refused')
            || str_contains($msg, 'no route to host')
            || str_contains($msg, 'connection timed out')
            || str_contains($msg, 'name or service not known')
            || str_contains($msg, 'could not connect to')
            || str_contains($msg, 'unable to connect')
        ) {
            return [
                'category' => 'No se pudo conectar al servidor SFTP',
                'hint' => 'Verifica que el host y el puerto son correctos, que el firewall del servidor Hawkins permite conexiones entrantes en el puerto SSH, y que la red entre Coolify y ese servidor funciona.',
            ];
        }

        // --- Disk full ----------------------------------------------
        if (
            str_contains($msg, 'no space left')
            || str_contains($msg, 'disk full')
            || str_contains($msg, 'write failed')
            || str_contains($msg, 'cannot write')
            || str_contains($msg, 'quota exceeded')
        ) {
            return [
                'category' => 'Espacio insuficiente en disco',
                'hint' => 'Libera espacio en /data/coolify/backups/ o baja el valor de "Retener últimos N backups". Puedes ver el espacio libre en la pestaña "Backup Local".',
            ];
        }

        // --- Permission denied --------------------------------------
        if (str_contains($msg, 'permission denied')) {
            return [
                'category' => 'Permisos denegados al escribir el backup',
                'hint' => 'Verifica los permisos de escritura en la ruta de destino (local o SFTP). El usuario SFTP debe poder escribir en la ruta remota configurada.',
            ];
        }

        // --- Docker container not running ---------------------------
        if (
            str_contains($msg, 'no such container')
            || str_contains($msg, 'is not running')
            || str_contains($msg, 'no such object')
        ) {
            return [
                'category' => 'Uno de los contenedores no está disponible',
                'hint' => 'Un contenedor (base de datos o aplicación) no estaba corriendo cuando el backup intentó dumpearlo. Arráncalo desde el panel de Coolify y vuelve a ejecutar el backup.',
            ];
        }

        // --- Database auth ------------------------------------------
        if (
            str_contains($msg, 'access denied')
            && (str_contains($msg, 'user') || str_contains($msg, 'password'))
        ) {
            return [
                'category' => 'Credenciales de base de datos incorrectas',
                'hint' => 'La contraseña root del contenedor de base de datos no coincide con la esperada. Esto suele pasar si alguien cambió MARIADB_ROOT_PASSWORD / MYSQL_ROOT_PASSWORD en el servicio después de arrancarlo. Regenera el contenedor o corrige la variable.',
            ];
        }

        // --- Missing source tarball (sftp-only mode) ----------------
        if (
            str_contains($msg, 'no existe el tarball')
            || str_contains($msg, 'tarball origen')
        ) {
            return [
                'category' => 'No hay tarball local de origen',
                'hint' => 'El modo "Solo SFTP" necesita que exista un backup local previo para re-subirlo. Ejecuta primero un backup local (o el modo "Ambos") desde la pestaña correspondiente.',
            ];
        }

        // --- Incomplete SFTP configuration --------------------------
        if (
            str_contains($msg, 'configuración sftp incompleta')
            || str_contains($msg, 'clave privada vacía')
            || str_contains($msg, 'contraseña vacía')
            || str_contains($msg, 'sftp está desactivado')
        ) {
            return [
                'category' => 'Configuración SFTP incompleta',
                'hint' => 'Abre la pestaña "Backup Servidor Hawkins" y rellena host, usuario, ruta remota y credenciales (password o clave SSH privada). Luego pulsa "Probar conexión" para verificar.',
            ];
        }

        // --- Server record missing ----------------------------------
        if (str_contains($msg, 'sin servidor') || str_contains($msg, 'servidor coolify local')) {
            return [
                'category' => 'Configuración de servidor de Coolify incorrecta',
                'hint' => 'Un recurso no tiene servidor asociado en Coolify, o no se encuentra el servidor local (id=0). Revisa la configuración de servidores en el panel.',
            ];
        }

        // --- Password missing from container env --------------------
        if (str_contains($msg, 'sin contraseña root')) {
            return [
                'category' => 'Falta la contraseña root del contenedor de base de datos',
                'hint' => 'No se pudo extraer MARIADB_ROOT_PASSWORD / MYSQL_ROOT_PASSWORD del entorno del contenedor. Asegúrate de que el servicio está corriendo con esa variable definida.',
            ];
        }

        // --- Size mismatch (truncated SFTP upload) ------------------
        if (str_contains($msg, 'tamaño remoto') && str_contains($msg, 'no coincide')) {
            return [
                'category' => 'La subida SFTP quedó truncada',
                'hint' => 'El tamaño del fichero remoto no coincide con el local, lo que indica que la conexión se cortó durante la subida. Verifica la estabilidad de la red al servidor SFTP.',
            ];
        }

        return [
            'category' => 'Error técnico sin categorizar',
            'hint' => 'Revisa el historial de backups en la pestaña "Historial" de Coolify → Backups para más contexto. Si se repite, comparte el mensaje técnico con el equipo de soporte.',
        ];
    }

    /**
     * Builds + sends the failure email. The body is intentionally
     * plain text (Mail::raw) so it survives any mail transport
     * without HTML rendering issues.
     */
    private function notifyFailure(Team $team, TeamBackupSetting $settings, string $step, \Throwable $e, string $batchUuid): void
    {
        if (! $settings->notify_on_failure) {
            return;
        }

        $recipients = is_array($settings->notification_emails) && ! empty($settings->notification_emails)
            ? $settings->notification_emails
            : TeamBackupSetting::defaultNotificationEmails();

        $rawMessage = $e->getMessage();
        $classification = $this->categorizeFailure($rawMessage);
        $category = $classification['category'];
        $hint = $classification['hint'];

        $freeBytes = function_exists('coolify_host_free_bytes')
            ? coolify_host_free_bytes(rtrim($settings->local_path, '/') ?: '/')
            : null;
        $freeHuman = $freeBytes !== null
            ? number_format($freeBytes / 1073741824, 2).' GB'
            : 'desconocido';

        $subject = "Coolify: {$step} FALLIDO — {$category}";

        $rawTrimmed = mb_substr($rawMessage, 0, 2000);
        $modeLabel = match ($this->mode) {
            'local-only' => 'Solo local',
            'sftp-only' => 'Solo SFTP',
            default => 'Ambos (local + SFTP)',
        };

        $body = "Hola,\n\n";
        $body .= "El backup automático de Coolify ha fallado después de un reintento.\n\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "RESUMEN\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "Equipo:      {$team->name} (#{$team->id})\n";
        $body .= "Tipo:        {$step}\n";
        $body .= "Modo:        {$modeLabel}\n";
        $body .= "Alcance:     {$this->scope}\n";
        $body .= "Disparador:  {$this->trigger}\n";
        $body .= "Hora:        ".now()->toDateTimeString()."\n";
        $body .= "Batch ID:    {$batchUuid}\n\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "MOTIVO DEL FALLO\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "{$category}\n\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "QUÉ REVISAR\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "{$hint}\n\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "ESTADO DEL SISTEMA\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "Espacio libre en disco (Coolify): {$freeHuman}\n";
        $body .= "Ruta local de backups: {$settings->local_path}\n\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "DETALLE TÉCNICO\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "{$rawTrimmed}\n\n";
        $body .= "────────────────────────────────────────\n";
        $body .= "Puedes ver el historial completo en el panel de Coolify → Backups → Historial.\n";

        try {
            foreach ($recipients as $to) {
                if (! is_string($to) || $to === '') {
                    continue;
                }
                Mail::raw($body, function ($m) use ($to, $subject) {
                    $m->to($to)->subject($subject);
                });
            }
        } catch (\Throwable $mailError) {
            // Swallow email errors — the DB run row already
            // carries the failure so the UI will still show it.
        }
    }
}
