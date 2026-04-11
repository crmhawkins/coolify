<?php

namespace App\Livewire\Project\Service;

use App\Jobs\GenerateServiceBackupJob;
use App\Models\Service;
use App\Models\ServiceBackupRun;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Per-service "Backup y descarga" tab.
 *
 * Reachable from the service configuration sub-menu only when
 * the stack contains at least one WordPress application — the
 * link in configuration.blade.php is gated by hasWordPress() and
 * this component re-checks the same predicate inside mount() so
 * a hand-typed URL on a Laravel/MariaDB/etc. service quietly 404s
 * the section instead of generating an unsupported backup.
 *
 * State on the page:
 *  - "Generar copia descargable" button (only enabled when no run
 *    is currently running for this service).
 *  - List of past runs for this service, ordered by created_at
 *    desc, capped to the last 20.
 *  - Per-row actions: download (when status=completed and not
 *    expired) and delete-from-history (any status; the Pune job
 *    handles disk cleanup, the user just clears their list).
 *
 * Polling: wire:poll.5s on the table so the user sees status
 * transitions (pending → running → completed) and the countdown
 * to expiry without having to refresh.
 */
class BackupDownload extends Component
{
    use AuthorizesRequests;

    public ?Service $service = null;

    public bool $available = false;

    /**
     * Cached list of runs to render. Refreshed by reloadRuns()
     * which fires from mount, after a manual generate, and from
     * the wire:poll loop. Capped to 20 newest rows so the UI
     * never has to render hundreds of expired entries.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $runs = [];

    public bool $hasRunning = false;

    public function mount(Service $service): void
    {
        $this->service = $service;
        $this->available = $this->serviceIsWordPress($service);

        $this->reloadRuns();
    }

    public function render()
    {
        return view('livewire.project.service.backup-download');
    }

    /**
     * Trigger a new generation. Creates a `pending` row and fires
     * GenerateServiceBackupJob onto the queue. Returns immediately
     * — the table picks up status changes via wire:poll.
     */
    public function generate(): void
    {
        if ($this->service === null) {
            return;
        }
        $this->authorize('update', $this->service);

        if (! $this->available) {
            $this->dispatch('error', 'Esta funcionalidad solo está disponible para servicios WordPress.');

            return;
        }

        // Don't queue a second run if there's already one in
        // flight for this service. The user can wait for it to
        // finish or fail.
        $hasInFlight = ServiceBackupRun::query()
            ->where('service_id', $this->service->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
        if ($hasInFlight) {
            $this->dispatch('error', 'Ya hay una copia en proceso para este servicio. Espera a que termine.');

            return;
        }

        // Resolve the team_id from the project row directly. We
        // reload the service with eager-loaded relations so a
        // Livewire round-trip doesn't leave us with a stale model
        // missing its environment relation, and we read team_id
        // straight off the project pivot — NOT via the
        // Service::team() helper or currentTeam(), both of which
        // turned out to be flaky for newly-created client users
        // (the original bug report).
        //
        // Fallback chain:
        //   1. fresh Service with environment+project loaded →
        //      read projects.team_id
        //   2. currentTeam()->id as a last resort
        //   3. error out (the user sees a clear toast)
        $freshService = Service::with('environment.project')->find($this->service->id);
        $teamId = 0;
        if ($freshService !== null) {
            $teamId = (int) (data_get($freshService, 'environment.project.team_id') ?? 0);
        }
        if ($teamId === 0) {
            $teamId = (int) (currentTeam()?->id ?? 0);
        }
        if ($teamId === 0) {
            $this->dispatch('error', 'No se pudo determinar el equipo de este servicio.');

            return;
        }

        $run = ServiceBackupRun::create([
            'service_id' => $this->service->id,
            'team_id' => $teamId,
            'user_id' => auth()->id(),
            'status' => 'pending',
            'last_message' => 'En cola, esperando worker…',
        ]);

        GenerateServiceBackupJob::dispatch($run->id);

        $this->reloadRuns();
        $this->dispatch('success', 'Copia en cola. La verás en la lista cuando esté lista.');
    }

    /**
     * Manual refresh — also fires from wire:poll. Hits the DB
     * directly because we want pagination/cap behaviour live.
     */
    #[On('refresh-service-backup-runs')]
    public function reloadRuns(): void
    {
        if ($this->service === null) {
            return;
        }

        $rows = ServiceBackupRun::query()
            ->where('service_id', $this->service->id)
            ->latest('created_at')
            ->limit(20)
            ->get();

        $this->runs = $rows->map(function (ServiceBackupRun $r) {
            return [
                'id' => $r->id,
                'status' => $r->status,
                'archive_name' => $r->archive_name,
                'size_bytes' => $r->size_bytes,
                'last_message' => $r->last_message,
                'created_at_h' => optional($r->created_at)->diffForHumans(),
                'created_at_iso' => optional($r->created_at)->toDateTimeString(),
                'expires_at_iso' => optional($r->expires_at)->toDateTimeString(),
                'expires_in_human' => $r->expires_at ? $r->expires_at->diffForHumans() : null,
                'duration_seconds' => $r->durationSeconds(),
                'is_downloadable' => $r->isDownloadable(),
                'download_url' => $r->isDownloadable()
                    ? route('service-backups.download', ['id' => $r->id])
                    : null,
                'show_error' => $r->status === 'failed',
            ];
        })->all();

        $this->hasRunning = collect($this->runs)
            ->contains(fn ($r) => in_array($r['status'], ['pending', 'running'], true));
    }

    /**
     * Remove a row from the user's history list. If the artifact
     * still exists on disk we leave it for the prune sweeper to
     * pick up — this method only touches the DB row, not the
     * filesystem, so a misclick can't lose anything that wasn't
     * about to expire anyway.
     */
    public function deleteRun(int $id): void
    {
        if ($this->service === null) {
            return;
        }
        $this->authorize('update', $this->service);

        $run = ServiceBackupRun::where('id', $id)
            ->where('service_id', $this->service->id)
            ->first();
        if ($run !== null) {
            $run->delete();
            $this->reloadRuns();
            $this->dispatch('success', 'Entrada eliminada del historial.');
        }
    }

    /**
     * The same predicate as Configuration::hasWordPress(),
     * duplicated here so this component is self-contained for the
     * (rare) case where Configuration is not the parent. Image
     * name match first, env-var sniff second.
     */
    private function serviceIsWordPress(Service $service): bool
    {
        foreach ($service->applications as $app) {
            if (str_contains(strtolower((string) $app->image), 'wordpress')) {
                return true;
            }
        }
        foreach ($service->applications as $app) {
            try {
                foreach ($app->environment_variables()->get() as $env) {
                    if (str_contains(strtoupper((string) $env->key), 'WORDPRESS')) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore — if env_vars relation explodes, fall
                // through to the negative result.
            }
        }

        return false;
    }
}
