<?php

namespace App\Jobs;

use App\Actions\Service\PruneExpiredServiceBackups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tiny scheduler-fired wrapper around PruneExpiredServiceBackups.
 *
 * Lives as its own job (instead of inlining the action call in the
 * Kernel) so it shows up in Horizon as a real job and so we get
 * the standard ShouldBeEncrypted + tries=1 plumbing for free.
 *
 * Wired to run every minute from app/Console/Kernel.php with
 * onOneServer() so a multi-host Horizon deployment doesn't double-
 * sweep. The action is idempotent regardless, but onOneServer()
 * keeps the noise down.
 */
class PruneExpiredServiceBackupsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(): void
    {
        PruneExpiredServiceBackups::run();
    }
}
