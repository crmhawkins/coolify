<?php

namespace App\Livewire\Project\Service;

use App\Jobs\GenerateServiceBackupJob;
use App\Models\Service;
use App\Models\ServiceBackupRun;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
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

    /**
     * Team id resolved at mount time and cached on the component
     * so subsequent click-time calls (generate, deleteRun,
     * reloadRuns) don't have to re-derive it from a Livewire-
     * hydrated model that may have lost its relation state.
     *
     * Three resolution sources in priority order:
     *   1. Raw DB join services → environments → projects → team_id
     *      — bypasses every Eloquent global scope.
     *   2. The parent Service model's environment.project.team_id
     *      walked via data_get (works when mount() is called with
     *      a freshly route-model-bound service).
     *   3. currentTeam()->id from the session (works for admin
     *      but often null for a fresh client that never switched
     *      teams explicitly).
     *
     * If all three fail, the error toast includes diagnostic
     * values so we can see which path returned nothing.
     */
    public int $resolvedTeamId = 0;

    public function mount(Service $service): void
    {
        $this->service = $service;
        $this->available = $this->serviceIsWordPress($service);
        $this->resolvedTeamId = $this->resolveTeamIdFor($service);

        $this->reloadRuns();
    }

    /**
     * Try each of the three team_id sources in order and return
     * the first non-zero value. Isolated into its own method so
     * mount() and generate() can both call it — generate() calls
     * it as a safety net in case $resolvedTeamId was serialized
     * back as 0 through a Livewire round trip.
     */
    private function resolveTeamIdFor(?Service $service): int
    {
        $svcId = (int) ($service?->id ?? 0);

        // Source 1: raw DB join, bypasses every global scope
        if ($svcId > 0) {
            $viaJoin = DB::table('services')
                ->join('environments', 'services.environment_id', '=', 'environments.id')
                ->join('projects', 'environments.project_id', '=', 'projects.id')
                ->where('services.id', $svcId)
                ->value('projects.team_id');
            if ($viaJoin !== null && (int) $viaJoin > 0) {
                return (int) $viaJoin;
            }
        }

        // Source 2: walk the Eloquent relation on the instance we
        // got in mount(). Freshly route-model-bound services
        // usually have environment loaded implicitly because the
        // parent Configuration component eager-loads it.
        $viaRelation = (int) (data_get($service, 'environment.project.team_id') ?? 0);
        if ($viaRelation > 0) {
            return $viaRelation;
        }

        // Source 3: current session team
        return (int) (currentTeam()?->id ?? 0);
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

        // Use the team id we resolved at mount time. If for some
        // weird Livewire hydration reason that came back as 0,
        // re-run the resolver here one more time.
        $teamId = $this->resolvedTeamId > 0
            ? $this->resolvedTeamId
            : $this->resolveTeamIdFor($this->service);

        if ($teamId === 0) {
            // Include the values we actually saw so if this ever
            // fires again we can see which source returned
            // nothing. The toast text is Spanish but the debug
            // suffix is intentionally left in English so the user
            // can paste it verbatim in a bug report.
            $svcId = (int) ($this->service?->id ?? 0);
            $svcUuid = (string) ($this->service?->uuid ?? '');
            $this->dispatch('error', "No se pudo determinar el equipo de este servicio. [svc_id={$svcId} uuid={$svcUuid}]");

            return;
        }

        // Cache the freshly resolved value so subsequent clicks
        // (deleteRun, or a retry after a transient network
        // hiccup) don't pay the DB join twice.
        $this->resolvedTeamId = $teamId;

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
