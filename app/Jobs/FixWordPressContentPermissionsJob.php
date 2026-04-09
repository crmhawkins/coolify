<?php

namespace App\Jobs;

use App\Actions\Service\FixWordPressContentPermissions;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queues a background run of FixWordPressContentPermissions against a
 * single service. Dispatched automatically by Heading::redeploy() and
 * Heading::pullAndRestartEvent() when the target service contains at
 * least one WordPress container, so users don't have to remember to
 * click the "Arreglar permisos" button after every deploy.
 *
 * Self-healing
 * ------------
 * Redeploys take seconds to minutes and the WordPress container might
 * not be fully up by the time this job runs. The action itself skips
 * non-running containers gracefully, but we still wire the usual
 * Laravel retry machinery (tries/backoff) so a transient SSH failure
 * on the first attempt doesn't leave the user staring at a broken
 * wp-content.
 *
 * Idempotent
 * ----------
 * FixWordPressContentPermissions is safe to run repeatedly: every
 * shell command inside it (chown/chmod/mkdir -p) is a no-op when the
 * target is already correct. Running the job twice in a row during
 * a redeploy is fine.
 *
 * Serialization
 * -------------
 * We serialize the service id (not the model itself) because Service
 * has relations (applications, environment_variables, server) that
 * shouldn't be frozen into the queue payload — we re-hydrate on
 * handle() to make sure we see fresh container status.
 */
class FixWordPressContentPermissionsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many times Laravel will retry this job before giving up.
     * The action itself is idempotent so retries are always safe.
     */
    public int $tries = 3;

    /**
     * Exponential backoff between retries (seconds). Covers the
     * typical "container is restarting" → "container is healthy"
     * transition which usually completes within 60-90 seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 30, 60];
    }

    /**
     * Hard ceiling so a wedged SSH connection can't hold a queue
     * worker forever. The action's shell command is usually under
     * 5 seconds per container on a warm cache, so 5 minutes is more
     * than enough even on a 1 GB wp-content tree.
     */
    public int $timeout = 300;

    public function __construct(public int $serviceId) {}

    public function handle(): void
    {
        $service = Service::find($this->serviceId);
        if (! $service) {
            Log::info('FixWordPressContentPermissionsJob: service no longer exists, skipping.', [
                'service_id' => $this->serviceId,
            ]);

            return;
        }

        // Refresh the applications relation so we see the current
        // post-deploy container status instead of whatever was in
        // memory when the job was enqueued.
        $service->load('applications');

        try {
            $result = FixWordPressContentPermissions::run($service);

            if ($result['ok']) {
                Log::info('FixWordPressContentPermissionsJob: wp-content permissions fixed.', [
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'containers' => array_map(fn ($c) => $c['container'] ?? null, $result['containers']),
                ]);

                return;
            }

            // The containers array may be empty during a retry if
            // nothing was running yet — that's the signal to fail
            // the attempt so Laravel retries on the backoff schedule.
            if (empty($result['containers'])) {
                throw new \RuntimeException('No running WordPress containers found (yet).');
            }

            // Some containers ran, some errored. Log full detail but
            // do not throw — retrying won't help if the script itself
            // returned a real chown/chmod error.
            Log::warning('FixWordPressContentPermissionsJob: partial failure on wp-content permissions.', [
                'service_id' => $service->id,
                'errors' => $result['errors'],
                'containers' => $result['containers'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('FixWordPressContentPermissionsJob: run failed, will retry if attempts remain.', [
                'service_id' => $service->id,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'error' => $e->getMessage(),
            ]);

            // Rethrow so Laravel applies the backoff + retry policy.
            throw $e;
        }
    }
}
// resync-marker 2026-04-08
