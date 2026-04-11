<?php

namespace App\Jobs;

use App\Actions\Service\GenerateServiceBackup;
use App\Models\ServiceBackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue job that drives a single ServiceBackupRun through
 * GenerateServiceBackup. Lives in its own queue job (not as a
 * direct ::dispatch on the action) so the UI gets a clean
 * "pending → running → completed | failed" loop driven by the
 * model row, and the operator can see the work in Horizon like
 * any other queued job.
 *
 * tries = 1 because the action is not idempotent — a half-run
 * leaves staging artifacts on disk that the second attempt would
 * have to clean up. We'd rather show "failed" in the UI and let
 * the operator click "Generar copia descargable" again.
 *
 * timeout = 7200 (2 h) because the file tar of a fat WordPress
 * (50 GB of uploads) can comfortably take that long over slow
 * disks. The action's individual remote commands also have their
 * own timeouts.
 *
 * The failed() hook flips the row to `failed` if the worker dies
 * before the action's own try/catch fires (OOM kill, queue
 * worker crash, signal). Without this, a hard worker crash leaves
 * a row stuck in `running` forever.
 */
class GenerateServiceBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public int $serviceBackupRunId) {}

    public function handle(): void
    {
        $run = ServiceBackupRun::find($this->serviceBackupRunId);
        if ($run === null) {
            // Row was deleted (cascade from service deletion or
            // manual cleanup) between dispatch and pickup. Nothing
            // to do, exit clean.
            return;
        }

        if ($run->status !== 'pending') {
            // Defensive: don't re-run a row that already completed,
            // failed or expired. Re-dispatching the same id should
            // be a no-op.
            return;
        }

        GenerateServiceBackup::run($run);
    }

    public function failed(\Throwable $e): void
    {
        $run = ServiceBackupRun::find($this->serviceBackupRunId);
        if ($run === null) {
            return;
        }
        // Only flip to failed if the action's own catch didn't
        // already do it. Avoids overwriting a more specific message
        // with a generic "Job failed".
        if ($run->status === 'running' || $run->status === 'pending') {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_message' => 'El proceso se interrumpió: '.mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }
}
