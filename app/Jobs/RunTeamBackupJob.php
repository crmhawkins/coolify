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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrator job for the team-wide "Backups" feature.
 *
 * Runs LOCAL first, then (on success, and if SFTP is enabled)
 * uploads the same tarball to the configured remote server. Both
 * steps record their own TeamBackupRun row so the UI can show
 * granular status, ETAs, and per-destination retention.
 *
 * `batch_uuid` groups the two rows of the same "full run" so they
 * render together in the history list.
 */
class RunTeamBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Up to 2h for the local step + 2h for the SFTP step in worst
     * case. The action-level timeouts keep individual commands
     * bounded separately.
     */
    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public string $scope = 'full',
        public string $trigger = 'manual',
        public bool $runSftpAfterLocal = true,
    ) {}

    public function handle(): void
    {
        $team = Team::find($this->teamId);
        if (! $team) {
            return;
        }
        $settings = TeamBackupSetting::forTeam($this->teamId);

        // Short-circuit: if NEITHER destination is enabled, there is
        // nothing to do. This matters for scheduled runs that land
        // here after someone disabled both toggles without removing
        // the cron — the dispatch loop is dumb and delegates the
        // "should I even run?" decision to here.
        if (! $settings->local_enabled && ! $settings->sftp_enabled) {
            return;
        }

        $batchUuid = (string) Str::uuid();

        // ------------------------------------------------------------
        // STEP 1 — Local backup (always runs if local_enabled is on)
        // ------------------------------------------------------------
        $localRun = null;
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
                RunLocalBackup::run($localRun);
                $localRun->refresh();
                if ($localRun->status === 'completed') {
                    $localTarball = (string) $localRun->artifact_path;
                    PruneLocalBackups::run($this->teamId);
                }
            } catch (\Throwable $e) {
                $this->notifyFailure($settings, 'Backup Local', $e->getMessage(), $batchUuid);
            }
        }

        // ------------------------------------------------------------
        // STEP 2 — SFTP upload (only if local succeeded AND sftp on)
        // ------------------------------------------------------------
        if (! $this->runSftpAfterLocal) {
            return;
        }
        if (! $settings->sftp_enabled) {
            return;
        }
        if ($localTarball === null || ! is_file($localTarball)) {
            // Nothing to upload. If local failed we already notified
            // above; if local was disabled, we can't derive a source
            // tarball so the feature design mandates "local must be
            // enabled to feed sftp". Flag a skipped row so it shows
            // up in history.
            TeamBackupRun::create([
                'team_id' => $this->teamId,
                'batch_uuid' => $batchUuid,
                'destination' => 'sftp',
                'scope' => $this->scope,
                'trigger' => $this->trigger,
                'status' => 'skipped',
                'started_at' => now(),
                'finished_at' => now(),
                'last_message' => 'SFTP saltado: no hay tarball local de origen.',
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
            'last_message' => 'Esperando al backup local…',
        ]);

        try {
            UploadBackupSftp::run($sftpRun, $localTarball);
        } catch (\Throwable $e) {
            $this->notifyFailure($settings, 'Backup SFTP', $e->getMessage(), $batchUuid);
        }
    }

    /**
     * Simple email notifier. Uses Laravel's raw Mail helper against
     * the explicit recipient list from TeamBackupSetting (defaults
     * are the hawkins emails the feature owner set). Keeps failures
     * visible without wiring into Coolify's per-team notification
     * channels, which would force the user to configure them first.
     */
    private function notifyFailure(TeamBackupSetting $settings, string $step, string $reason, string $batchUuid): void
    {
        if (! $settings->notify_on_failure) {
            return;
        }
        $recipients = is_array($settings->notification_emails) && ! empty($settings->notification_emails)
            ? $settings->notification_emails
            : TeamBackupSetting::defaultNotificationEmails();

        $reasonTrimmed = mb_substr($reason, 0, 2000);
        $body = "El {$step} del equipo #{$settings->team_id} ha fallado.\n\n"
            ."Batch: {$batchUuid}\n"
            ."Hora: ".now()->toDateTimeString()."\n\n"
            ."Motivo:\n{$reasonTrimmed}\n\n"
            ."Revisa la sección Backups en el panel de Coolify para más detalles.";

        try {
            foreach ($recipients as $to) {
                if (! is_string($to) || $to === '') {
                    continue;
                }
                Mail::raw($body, function ($m) use ($to, $step) {
                    $m->to($to)->subject("Coolify: {$step} FALLIDO");
                });
            }
        } catch (\Throwable $e) {
            // Don't let email errors break the job — the DB log
            // still has the failure recorded via the run row.
        }
    }
}
