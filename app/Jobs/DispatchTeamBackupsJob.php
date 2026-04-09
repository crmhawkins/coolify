<?php

namespace App\Jobs;

use App\Models\TeamBackupSetting;
use Cron\CronExpression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Every-minute tick that walks all team backup settings and fires
 * RunTeamBackupJob for the ones whose cron matches the current
 * minute in the instance timezone.
 *
 * Lives in its own file so the main Kernel::schedule() stays thin
 * and this job can be unit-tested in isolation with a fake clock.
 * Only enabled teams (`local_enabled || sftp_enabled`) are picked
 * up; disabled ones skip without touching the queue.
 *
 * Guards against double-firing by checking if there's already a
 * pending/running backup for the team — the RunTeamBackupJob does
 * the same check, but bailing early here saves a queue hop.
 */
class DispatchTeamBackupsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $tz = config('app.timezone') ?: 'UTC';
        $now = now($tz);

        TeamBackupSetting::query()
            ->where(function ($q) {
                $q->where('local_enabled', true)->orWhere('sftp_enabled', true);
            })
            ->get()
            ->each(function (TeamBackupSetting $s) use ($now, $tz) {
                $cron = (string) $s->local_cron;
                if ($cron === '') {
                    return;
                }
                try {
                    $expr = new CronExpression($cron);
                } catch (\Throwable $e) {
                    Log::warning("DispatchTeamBackupsJob: invalid cron for team {$s->team_id}: {$cron}");

                    return;
                }

                // CronExpression::isDue returns true during the
                // minute the expression matches. Pass the current
                // time + timezone explicitly so we don't rely on
                // the process-level timezone.
                if (! $expr->isDue($now, $tz)) {
                    return;
                }

                // Already pending/running? Skip this tick.
                $hasActive = \App\Models\TeamBackupRun::query()
                    ->where('team_id', $s->team_id)
                    ->whereIn('status', ['pending', 'running'])
                    ->exists();
                if ($hasActive) {
                    Log::info("DispatchTeamBackupsJob: team {$s->team_id} already has a backup in progress, skipping.");

                    return;
                }

                RunTeamBackupJob::dispatch(
                    (int) $s->team_id,
                    (string) $s->local_scope,
                    'scheduled',
                    true
                );
            });
    }
}
