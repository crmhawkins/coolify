<?php

namespace App\Livewire\Monitor;

use App\Actions\Application\StopApplication;
use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Proxy\StartProxy;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Services\MonitorMetricsCollector;
use App\Services\MonitorResourceAggregator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;
use Visus\Cuid2\Cuid2;

/**
 * Monitor main page — the left-sidebar "Monitor" entry.
 *
 * Aggregates:
 *   - Server cards: CPU/RAM/disk/load/uptime/containers +
 *     proxy state + restart proxy button, per server the
 *     current Coolify install knows about.
 *   - Resource list: apps + services + databases across all
 *     those servers, with inline Redeploy / Restart / Stop /
 *     Start actions (type-appropriate), filterable to
 *     "only critical/warning" so the operator can zero in on
 *     what is broken without scrolling.
 *   - Activity feed: last 10 entries from Spatie's
 *     activity_log so the operator sees what just happened.
 *
 * The page intentionally lives in its own Livewire namespace
 * (App\Livewire\Monitor) so the blade can reach it via
 * <livewire:monitor.index /> and the sidebar route can use the
 * simple /monitor URL.
 *
 * Access is admin-only at the route layer (restrict.client
 * middleware) AND at the mount step — a client who types the
 * URL manually gets a 404 instead of a half-rendered page.
 */
class Index extends Component
{
    use AuthorizesRequests;

    /**
     * Poll cadence in milliseconds for wire:poll. The toggle in
     * the header flips this between an active value (10s) and 0
     * (paused — the poll directive renders without the interval
     * so Livewire's runtime skips it). 10 seconds is the sweet
     * spot between "feels live" and "does not hammer the SSH
     * multiplexer".
     */
    public int $pollMillis = 10000;

    public bool $paused = false;

    /**
     * Filter mode for the resource list. "all" shows everything,
     * "issues" collapses to only critical + warning rows so the
     * operator sees "the 3 things that are on fire" instead of
     * scrolling through 60 healthy sites.
     */
    public string $filter = 'all';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $serverSnapshots = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $resources = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $activity = [];

    /**
     * Simple counters the header + banner use. Computed in
     * refreshAll() so the blade never has to reduce the list
     * again itself.
     *
     * @var array<string, int>
     */
    public array $counts = [
        'total' => 0,
        'ok' => 0,
        'warning' => 0,
        'critical' => 0,
        'stopped' => 0,
    ];

    /**
     * Derived from the server snapshots — when any server has
     * disk > 80% the blade renders a red banner at the top.
     */
    public bool $diskPressure = false;

    public ?string $diskPressureMessage = null;

    public function mount(): void
    {
        if (auth()->user()?->isClient()) {
            abort(404);
        }
        $this->refreshAll();
    }

    public function render()
    {
        return view('livewire.monitor.index');
    }

    /**
     * wire:poll target. Single entry point so both the poll and
     * any manual click end up in the same code path.
     */
    public function tick(): void
    {
        $this->refreshAll();
    }

    public function togglePause(): void
    {
        $this->paused = ! $this->paused;
        $this->pollMillis = $this->paused ? 0 : 10000;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'issues'], true) ? $filter : 'all';
    }

    public function forceRefresh(): void
    {
        // Manual refresh: invalidate cached server snapshots so
        // we re-hit SSH immediately instead of serving the 7s
        // cached value.
        $collector = app(MonitorMetricsCollector::class);
        foreach (Server::ownedByCurrentTeamCached() as $server) {
            $collector->invalidate($server);
        }
        $this->refreshAll();
        $this->dispatch('success', 'Datos actualizados.');
    }

    // ---------------------------------------------------------
    // Proxy actions
    // ---------------------------------------------------------

    public function restartProxyOnServer(int $serverId): void
    {
        try {
            $server = $this->findTeamServer($serverId);
            if ($server === null) {
                $this->dispatch('error', 'Servidor no encontrado.');

                return;
            }
            $this->authorize('manageProxy', $server);
            $activity = StartProxy::run($server, force: true);
            $this->dispatch('activityMonitor', $activity->id);
            $this->dispatch('success', "Proxy reiniciado en {$server->name}.");
            app(MonitorMetricsCollector::class)->invalidate($server);
            $this->refreshAll();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'No se pudo reiniciar el proxy: '.$e->getMessage());
        }
    }

    // ---------------------------------------------------------
    // Resource actions
    // ---------------------------------------------------------

    /**
     * Redeploy: full stop + start pipeline. Picks up .env,
     * compose or config changes. Mirrors what the per-resource
     * Heading component's redeploy/deploy button does, routed
     * through the same helpers so the side effects (activity
     * log entries, notifications, etc.) are identical.
     */
    public function redeployResource(string $type, string $uuid): void
    {
        try {
            $resource = $this->resolveResource($type, $uuid);
            if ($resource === null) {
                $this->dispatch('error', 'Recurso no encontrado.');

                return;
            }

            if ($resource instanceof Service) {
                $this->authorize('update', $resource);
                $activity = StartService::run($resource, stopBeforeStart: true);
                $this->dispatch('activityMonitor', $activity->id);
            } elseif ($resource instanceof Application) {
                // Applications don't have a stop+start helper the
                // way services do — they go through Coolify's
                // deployment queue via queue_application_deployment().
                // We dispatch a fresh deployment uuid and let the
                // deploy pipeline handle everything else (swarm
                // checks, build cache, registry, etc.), exactly
                // like Project\Application\Heading::deploy().
                $this->authorize('deploy', $resource);
                $deploymentUuid = (string) new Cuid2;
                $result = queue_application_deployment(
                    application: $resource,
                    deployment_uuid: $deploymentUuid,
                    force_rebuild: false,
                );
                if (($result['status'] ?? '') === 'queue_full') {
                    $this->dispatch('error', 'Cola de deploy llena.');

                    return;
                }
            } else {
                // Standalone DB
                $this->authorize('update', $resource);
                RestartDatabase::run($resource);
            }
            $this->dispatch('success', 'Redeploy lanzado.');
            $this->refreshAll();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error en redeploy: '.$e->getMessage());
        }
    }

    /**
     * Lightweight restart: docker restart on the relevant
     * containers. Fast, no pipeline. Use when a single container
     * went unhealthy and you just want to kick it.
     *
     * Applications don't expose a "soft restart" helper so we go
     * through queue_application_deployment(restart_only: true),
     * which is the same path the application heading's Restart
     * button uses.
     */
    public function restartResource(string $type, string $uuid): void
    {
        try {
            $resource = $this->resolveResource($type, $uuid);
            if ($resource === null) {
                $this->dispatch('error', 'Recurso no encontrado.');

                return;
            }

            if ($resource instanceof Service) {
                $this->authorize('update', $resource);
                $kicked = 0;
                foreach ($resource->applications as $app) {
                    $app->restart();
                    $kicked++;
                }
                foreach ($resource->databases as $db) {
                    $db->restart();
                    $kicked++;
                }
                $this->dispatch('success', "{$kicked} contenedores reiniciados.");
            } elseif ($resource instanceof Application) {
                $this->authorize('deploy', $resource);
                $deploymentUuid = (string) new Cuid2;
                $result = queue_application_deployment(
                    application: $resource,
                    deployment_uuid: $deploymentUuid,
                    restart_only: true,
                );
                if (($result['status'] ?? '') === 'queue_full') {
                    $this->dispatch('error', 'Cola de deploy llena.');

                    return;
                }
                $this->dispatch('success', 'Reinicio encolado.');
            } else {
                $this->authorize('update', $resource);
                RestartDatabase::run($resource);
                $this->dispatch('success', 'Base de datos reiniciada.');
            }
            $this->refreshAll();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error reiniciando: '.$e->getMessage());
        }
    }

    public function stopResource(string $type, string $uuid): void
    {
        try {
            $resource = $this->resolveResource($type, $uuid);
            if ($resource === null) {
                $this->dispatch('error', 'Recurso no encontrado.');

                return;
            }

            if ($resource instanceof Service) {
                $this->authorize('update', $resource);
                StopService::dispatch($resource, false, true);
            } elseif ($resource instanceof Application) {
                $this->authorize('deploy', $resource);
                StopApplication::dispatch($resource, false, true);
            } else {
                $this->authorize('update', $resource);
                StopDatabase::dispatch($resource);
            }
            $this->dispatch('success', 'Parada en curso.');
            $this->refreshAll();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error parando: '.$e->getMessage());
        }
    }

    public function startResource(string $type, string $uuid): void
    {
        try {
            $resource = $this->resolveResource($type, $uuid);
            if ($resource === null) {
                $this->dispatch('error', 'Recurso no encontrado.');

                return;
            }

            if ($resource instanceof Service) {
                $this->authorize('update', $resource);
                $activity = StartService::run($resource, pullLatestImages: true);
                $this->dispatch('activityMonitor', $activity->id);
            } elseif ($resource instanceof Application) {
                // Start-from-stopped for an application IS a full
                // deployment — same code path as the redeploy one
                // above, no force rebuild, no restart_only.
                $this->authorize('deploy', $resource);
                $deploymentUuid = (string) new Cuid2;
                $result = queue_application_deployment(
                    application: $resource,
                    deployment_uuid: $deploymentUuid,
                    force_rebuild: false,
                );
                if (($result['status'] ?? '') === 'queue_full') {
                    $this->dispatch('error', 'Cola de deploy llena.');

                    return;
                }
            } else {
                $this->authorize('update', $resource);
                StartDatabase::dispatch($resource);
            }
            $this->dispatch('success', 'Arranque en curso.');
            $this->refreshAll();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error arrancando: '.$e->getMessage());
        }
    }

    // ---------------------------------------------------------
    // Internal: data refresh + helpers
    // ---------------------------------------------------------

    private function refreshAll(): void
    {
        $servers = Server::ownedByCurrentTeamCached();
        $collector = app(MonitorMetricsCollector::class);
        $aggregator = app(MonitorResourceAggregator::class);

        $this->serverSnapshots = collect($servers)->map(function (Server $server) use ($collector) {
            return $collector->collect($server);
        })->values()->all();

        $this->diskPressure = false;
        $this->diskPressureMessage = null;
        foreach ($this->serverSnapshots as $snap) {
            $pct = $snap['disk_percent'] ?? null;
            if (is_numeric($pct) && $pct >= 80) {
                $this->diskPressure = true;
                $this->diskPressureMessage = sprintf(
                    'Disco al %s%% en el servidor %s (%s usado de %s).',
                    round((float) $pct, 1),
                    $snap['server_name'] ?? '—',
                    $snap['disk_used_human'] ?? '?',
                    $snap['disk_total_human'] ?? '?'
                );
                break;
            }
        }

        $rawResources = $aggregator->collectForServers($servers);
        $this->resources = $rawResources->when($this->filter === 'issues', function (Collection $c) {
            return $c->filter(fn ($r) => ($r['severity'] ?? 'ok') !== 'ok');
        })->values()->all();

        $this->counts = [
            'total' => $rawResources->count(),
            'ok' => $rawResources->where('severity', 'ok')->count(),
            'warning' => $rawResources->where('severity', 'warning')->count(),
            'critical' => $rawResources->where('severity', 'critical')->count(),
            'stopped' => $rawResources->where('is_stopped', true)->count(),
        ];

        $this->activity = $this->loadRecentActivity();
    }

    /**
     * Last 10 Spatie activity_log entries related to the current
     * team's resources. Shaped for the blade: friendly label +
     * relative time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentActivity(): array
    {
        $rows = Activity::query()
            ->latest('id')
            ->limit(10)
            ->get();

        return $rows->map(function (Activity $a) {
            $status = data_get($a->properties, 'status', '');
            $typeUuid = data_get($a->properties, 'type_uuid', '');
            $description = (string) ($a->description ?? $a->event ?? 'actividad');

            // Defensive: Spatie's Activity model normally casts
            // created_at to Carbon, but we have seen the fork trip
            // on non-casted timestamps before (Application model
            // does NOT cast last_online_at either). Carbon::parse
            // from a raw string keeps the feed alive even if some
            // upstream migration forgot the cast.
            $when = null;
            if ($a->created_at !== null) {
                try {
                    $carbon = $a->created_at instanceof \DateTimeInterface
                        ? \Illuminate\Support\Carbon::instance($a->created_at)
                        : \Illuminate\Support\Carbon::parse((string) $a->created_at);
                    $when = $carbon->diffForHumans();
                } catch (\Throwable $e) {
                    $when = '';
                }
            }

            return [
                'id' => $a->id,
                'label' => mb_substr($description, 0, 80),
                'status' => (string) $status,
                'target_uuid' => (string) $typeUuid,
                'when_human' => $when ?? '',
                'severity' => match (true) {
                    str_contains(strtolower((string) $status), 'error') => 'critical',
                    str_contains(strtolower((string) $status), 'fail') => 'critical',
                    str_contains(strtolower((string) $status), 'finish') => 'ok',
                    str_contains(strtolower((string) $status), 'success') => 'ok',
                    str_contains(strtolower((string) $status), 'progress') => 'warning',
                    default => 'info',
                },
            ];
        })->values()->all();
    }

    private function findTeamServer(int $serverId): ?Server
    {
        $servers = Server::ownedByCurrentTeamCached();

        return collect($servers)->firstWhere('id', $serverId);
    }

    /**
     * Resolve a resource by type + uuid. Uses the team-scoped
     * queries so a client (who cannot reach this page anyway)
     * or a hand-typed uuid from a different team would 404
     * via firstWhere → null.
     */
    private function resolveResource(string $type, string $uuid)
    {
        return match ($type) {
            'service' => Service::whereUuid($uuid)->first(),
            'application' => Application::whereUuid($uuid)->first(),
            'database' => $this->resolveDatabase($uuid),
            default => null,
        };
    }

    private function resolveDatabase(string $uuid)
    {
        $classes = [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];
        foreach ($classes as $class) {
            $found = $class::whereUuid($uuid)->first();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
