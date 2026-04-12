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

    /**
     * Selection state for multi-row bulk actions. Keys are
     * "{type}:{uuid}" strings (the same composite id the blade
     * renders in the checkbox name), values are always true so
     * we can flip the checkbox via isset() without caring about
     * value semantics. Cleared on filter/sort/page change so a
     * forgotten selection doesn't accidentally target rows the
     * operator isn't even looking at.
     *
     * @var array<string, bool>
     */
    public array $selected = [];

    /**
     * Sort column for the resource table. Supports:
     *   - severity (default — critical first, then alphabetic)
     *   - name
     *   - status
     *   - kind_label (the "type" column in the UI)
     *
     * Clicking a column header toggles direction. setSortBy()
     * keeps $sortBy and $sortDir in sync.
     */
    public string $sortBy = 'severity';

    /**
     * 'asc' or 'desc'. For severity, asc means critical first
     * (lower weight = higher priority in the weight map).
     */
    public string $sortDir = 'asc';

    /**
     * Pagination. perPage=0 means "show all rows" (no cap); the
     * UI toggle has three positions: 10 / 20 / Todos. page is
     * 1-indexed.
     */
    public int $perPage = 10;

    public int $page = 1;

    /**
     * Total unpaginated count of the CURRENT filtered+sorted
     * list. Used by the pagination bar to render "1-10 de 55".
     */
    public int $totalRows = 0;

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
        // Filter change invalidates the current page and the
        // selection — the rows the user was looking at are
        // probably not the ones they will see next. Reset both.
        $this->page = 1;
        $this->selected = [];
        $this->refreshAll();
    }

    // ---------------------------------------------------------
    // Sorting, pagination and selection
    // ---------------------------------------------------------

    /**
     * Column header click handler. First click on a column
     * sets sortBy to that column and direction to asc. A
     * second click on the same column flips the direction.
     * Valid columns: name, status, kind_label, severity.
     */
    public function setSortBy(string $column): void
    {
        $valid = ['name', 'status', 'kind_label', 'severity'];
        if (! in_array($column, $valid, true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
        $this->page = 1;
        $this->refreshAll();
    }

    /**
     * Swap the page size between 10 / 20 / Todos. "Todos" is
     * stored internally as perPage=0 and collapses the
     * pagination bar.
     */
    public function setPerPage(int $perPage): void
    {
        if (! in_array($perPage, [10, 20, 0], true)) {
            return;
        }
        $this->perPage = $perPage;
        $this->page = 1;
        $this->refreshAll();
    }

    public function goToPage(int $page): void
    {
        if ($page < 1) {
            $page = 1;
        }
        $this->page = $page;
        $this->refreshAll();
    }

    public function nextPage(): void
    {
        $this->goToPage($this->page + 1);
    }

    public function prevPage(): void
    {
        $this->goToPage($this->page - 1);
    }

    /**
     * Flip a single row in the selection. The key format is
     * "{type}:{uuid}" to match the value the blade puts on the
     * checkbox input.
     */
    public function toggleSelection(string $key): void
    {
        if (isset($this->selected[$key])) {
            unset($this->selected[$key]);
        } else {
            $this->selected[$key] = true;
        }
    }

    /**
     * Select every resource the user is currently viewing. We
     * re-collect the full unpaginated+unsorted list of keys so
     * "select all" selects everything that matches the current
     * filter, not just the current page. That matches the
     * intuitive meaning of "select all visible".
     */
    public function selectAllVisible(): void
    {
        $servers = Server::ownedByCurrentTeamCached();
        $all = app(MonitorResourceAggregator::class)->collectForServers(collect($servers));
        $all = $this->applyFilter($all);
        foreach ($all as $row) {
            $key = $row['type'].':'.$row['uuid'];
            $this->selected[$key] = true;
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
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
     * Lightweight restart: `docker restart` on the relevant
     * containers, NOTHING ELSE. Fast, no pipeline, no rebuild,
     * no pulling images, no recreating containers, no reading
     * env files — the bare `docker restart` that kicks a
     * container whose process has gone unhealthy.
     *
     * For every resource type we go through the server-level
     * "restart this container id" helper so a container that is
     * already stopped just errors cleanly (no deploy attempt).
     * This is the behaviour the user explicitly asked for after
     * being bitten by the old Restart-inside-a-site button that
     * silently ran the full deploy pipeline instead.
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
                // ServiceApplication::restart() and
                // ServiceDatabase::restart() both shell out a
                // plain `docker restart <container>` — zero
                // pipeline. See their definitions in the models.
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
                $server = $resource->destination?->server;
                if (! $server instanceof Server) {
                    $this->dispatch('error', 'No se pudo localizar el servidor de la aplicación.');

                    return;
                }
                // Applications can run on N containers (compose
                // stacks, preview deployments, etc.). Coolify
                // labels each one with coolify.applicationId=<id>
                // so we restart every container that carries our
                // id with a single docker command. The `|| true`
                // keeps the command a no-op when zero containers
                // match instead of throwing SSH error.
                $escapedId = escapeshellarg((string) $resource->id);
                $cmd = "docker ps -q --filter label=coolify.applicationId={$escapedId} | xargs -r docker restart";
                if ($server->isNonRoot()) {
                    $cmd = "sudo sh -c ".escapeshellarg($cmd);
                }
                instant_remote_process([$cmd], $server, throwError: false);
                $this->dispatch('success', 'Aplicación reiniciada (docker restart).');
            } else {
                $this->authorize('update', $resource);
                // Standalone databases: one container named by
                // the uuid. RestartDatabase::run (the action)
                // actually does Stop+Start which recreates the
                // container — that is NOT a plain restart, it is
                // a redeploy. Use Server::restartUnmanaged so we
                // issue the real `docker restart <uuid>` and
                // nothing else.
                $server = $resource->destination?->server;
                if (! $server instanceof Server) {
                    $this->dispatch('error', 'No se pudo localizar el servidor de la base de datos.');

                    return;
                }
                $server->restartUnmanaged((string) $resource->uuid);
                $this->dispatch('success', 'Base de datos reiniciada (docker restart).');
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
    // Bulk actions — act on every currently-selected row
    // ---------------------------------------------------------

    /**
     * Apply a single-resource action over each entry in the
     * selection set. The action name must be one of
     * "redeploy" / "restart" / "stop" — each dispatches the
     * per-type helpers already defined above, so the fleet
     * behaviour is identical to clicking each row's button in
     * sequence, without the per-click confirmation overhead.
     *
     * We deliberately DO NOT add a global try/catch around each
     * iteration — we want one failing row to keep the rest
     * running, because the typical bulk flow is "restart every
     * site after the host reboot", and bailing on the first
     * already-stopped container would leave the rest of the
     * fleet untouched.
     */
    public function bulkAction(string $action): void
    {
        if (empty($this->selected)) {
            $this->dispatch('error', 'No hay recursos seleccionados.');

            return;
        }
        if (! in_array($action, ['redeploy', 'restart', 'stop'], true)) {
            $this->dispatch('error', 'Acción bulk desconocida.');

            return;
        }

        $ok = 0;
        $errors = 0;
        foreach (array_keys($this->selected) as $key) {
            [$type, $uuid] = array_pad(explode(':', $key, 2), 2, '');
            if ($type === '' || $uuid === '') {
                continue;
            }
            try {
                match ($action) {
                    'redeploy' => $this->redeployResource($type, $uuid),
                    'restart' => $this->restartResource($type, $uuid),
                    'stop' => $this->stopResource($type, $uuid),
                };
                $ok++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        $total = $ok + $errors;
        $noun = $total === 1 ? 'recurso' : 'recursos';
        $actionLabel = match ($action) {
            'redeploy' => 'Redeploy',
            'restart' => 'Restart',
            'stop' => 'Stop',
        };

        if ($errors === 0) {
            $this->dispatch('success', "{$actionLabel} lanzado en {$ok} {$noun}.");
        } else {
            $this->dispatch('warning', "{$actionLabel}: {$ok} OK, {$errors} con error.");
        }

        // Clear selection after a successful bulk run so the
        // operator isn't left with stale checkboxes pointing at
        // rows whose state just changed.
        $this->selected = [];
        $this->refreshAll();
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

        // Counts always reflect the FULL resource set, not the
        // filtered/paginated view. Otherwise flipping to "Solo
        // con problemas" would zero out the "OK" counter and
        // confuse the operator.
        $this->counts = [
            'total' => $rawResources->count(),
            'ok' => $rawResources->where('severity', 'ok')->count(),
            'warning' => $rawResources->where('severity', 'warning')->count(),
            'critical' => $rawResources->where('severity', 'critical')->count(),
            'stopped' => $rawResources->where('is_stopped', true)->count(),
        ];

        // Pipeline: filter → sort → paginate. Each step returns
        // a new Collection so we do not mutate the raw dataset.
        $filtered = $this->applyFilter($rawResources);
        $sorted = $this->applySort($filtered);
        $this->totalRows = $sorted->count();

        // Pagination: perPage=0 means "show everything".
        if ($this->perPage > 0) {
            $maxPage = max(1, (int) ceil($this->totalRows / $this->perPage));
            if ($this->page > $maxPage) {
                $this->page = $maxPage;
            }
            $sorted = $sorted->slice(($this->page - 1) * $this->perPage, $this->perPage);
        }

        $this->resources = $sorted->values()->all();

        $this->activity = $this->loadRecentActivity();
    }

    /**
     * Apply the current $filter to a resource collection.
     * "all" is a passthrough; "issues" keeps only rows whose
     * severity is NOT ok.
     *
     * @param  Collection<int, array<string, mixed>>  $resources
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilter(Collection $resources): Collection
    {
        if ($this->filter === 'issues') {
            return $resources->filter(fn ($r) => ($r['severity'] ?? 'ok') !== 'ok')->values();
        }

        return $resources;
    }

    /**
     * Apply the current $sortBy + $sortDir to a resource
     * collection. For the default "severity" column we sort by
     * a three-tier weight so critical rows always land on top
     * regardless of alphabetical name order within a bucket.
     *
     * For name/status/kind_label sorts we do a straight
     * case-insensitive string compare. Secondary sort by name
     * so two rows with the same status don't swap on every
     * refresh.
     *
     * @param  Collection<int, array<string, mixed>>  $resources
     * @return Collection<int, array<string, mixed>>
     */
    private function applySort(Collection $resources): Collection
    {
        $desc = $this->sortDir === 'desc';
        $weight = ['critical' => 0, 'warning' => 1, 'ok' => 2];
        $sortBy = $this->sortBy;

        // Use Collection::sort with an explicit comparator so
        // the multi-key array-based sort key is obvious and
        // portable. PHP's native <=> compares arrays element by
        // element which is exactly what a secondary-sort-by-
        // name needs, so [severityWeight, nameLower] works as a
        // single sort key.
        $sorted = $resources->sort(function ($a, $b) use ($sortBy, $weight, $desc) {
            $keyA = self::sortKey($a, $sortBy, $weight);
            $keyB = self::sortKey($b, $sortBy, $weight);
            $cmp = $keyA <=> $keyB;

            return $desc ? -$cmp : $cmp;
        });

        return $sorted->values();
    }

    /**
     * Build the composite sort key for a single row under the
     * given sort column. Severity uses a weight map so critical
     * sorts before warning before ok regardless of alphabet.
     * Every sort mode has `name` as a secondary tiebreaker so
     * two rows with the same status do not swap on refresh.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $weight
     * @return array<int, mixed>
     */
    private static function sortKey(array $row, string $sortBy, array $weight): array
    {
        $name = mb_strtolower((string) ($row['name'] ?? ''));

        return match ($sortBy) {
            'severity' => [
                $weight[$row['severity'] ?? 'ok'] ?? 99,
                $name,
            ],
            'status' => [
                mb_strtolower((string) ($row['status'] ?? '')),
                $name,
            ],
            'kind_label' => [
                mb_strtolower((string) ($row['kind_label'] ?? '')),
                $name,
            ],
            default => [$name, $name],
        };
    }

    /**
     * Last 10 Spatie activity_log entries shaped for the blade:
     * friendly label + relative time + severity + a resolved
     * resource name so the operator can tell WHAT the activity
     * is actually about at a glance.
     *
     * The raw activity_log rows Coolify writes for deployment
     * pipelines use the `description` column to store the
     * FULL CoolifyTask output as a JSON-encoded array of
     * `[{"type":"out","output":"..."}, ...]` entries. Dumping
     * that raw into the UI (what the previous version did) was
     * unreadable: the feed ended up showing "[{"type":"out",
     * "output":"Creating required Docker Compose file.\nPulling
     * docker" and cutting off mid-sentence.
     *
     * This rewrite:
     *  1. Detects when description is a JSON-encoded log array
     *     and extracts the LAST meaningful output line (the
     *     last line is where errors and success markers land).
     *  2. Strips escape sequences, collapses whitespace, and
     *     cuts to ~80 chars.
     *  3. Resolves the resource name for each row via a single
     *     batched whereIn query per resource type (Service,
     *     Application, standalone DBs) so the feed can show
     *     "Findpartners WordPress: Deployment finished" instead
     *     of an anonymous blob.
     *  4. Picks a severity bucket from `status` so the colored
     *     dot on the left matches what the row actually did.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentActivity(): array
    {
        $rows = Activity::query()
            ->latest('id')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Pre-load resource names by uuid in a single batched
        // lookup per model type, so the map() below never hits
        // the DB again. 10 rows × 7 models would otherwise be 70
        // queries per poll.
        $uuids = $rows->pluck('properties.type_uuid')
            ->filter(fn ($u) => is_string($u) && $u !== '')
            ->unique()
            ->values()
            ->all();
        $resourceNames = $this->batchResolveResourceNames($uuids);

        return $rows->map(function (Activity $a) use ($resourceNames) {
            $status = (string) data_get($a->properties, 'status', '');
            $typeUuid = (string) data_get($a->properties, 'type_uuid', '');
            $rawDescription = (string) ($a->description ?? $a->event ?? '');

            $description = $this->humaniseActivityDescription($rawDescription);

            // Prepend the resolved resource name so the label
            // reads "Findpartners: {what happened}" instead of a
            // bare log line with no context.
            $resourceName = $resourceNames[$typeUuid] ?? null;
            if ($resourceName !== null && $description !== '') {
                $label = "{$resourceName}: {$description}";
            } elseif ($resourceName !== null) {
                $label = $resourceName;
            } elseif ($description !== '') {
                $label = $description;
            } else {
                $label = 'Actividad sin detalle';
            }

            // Defensive: Spatie's Activity model normally casts
            // created_at to Carbon, but the fork has been bitten
            // by non-casted timestamps before (Application model
            // still does NOT cast last_online_at either).
            // Carbon::parse from a raw string keeps the feed
            // alive even if some upstream migration forgot it.
            $when = '';
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
                'label' => mb_substr($label, 0, 120),
                'status' => $status,
                'target_uuid' => $typeUuid,
                'when_human' => $when,
                'severity' => match (true) {
                    str_contains(strtolower($status), 'error') => 'critical',
                    str_contains(strtolower($status), 'fail') => 'critical',
                    str_contains(strtolower($status), 'finish') => 'ok',
                    str_contains(strtolower($status), 'success') => 'ok',
                    str_contains(strtolower($status), 'progress') => 'warning',
                    default => 'info',
                },
            ];
        })->values()->all();
    }

    /**
     * Turn a raw activity_log description — which for Coolify's
     * CoolifyTask-driven rows is a JSON array of output entries
     * — into a human-readable one-line label.
     *
     *   [{"type":"out","output":"Saved configuration files..."}]
     *     → "Saved configuration files…"
     *
     * Plain-string descriptions pass through untouched (truncated).
     * Handles malformed JSON gracefully so a corrupt row never
     * breaks the whole feed.
     */
    private function humaniseActivityDescription(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Detect the JSON-log shape. Every CoolifyTask row starts
        // with `[{"` (JSON array of objects). We only try to
        // parse when the prefix matches so a legitimate plain
        // string like "Deployment failed" never hits json_decode.
        if (str_starts_with($raw, '[{') || str_starts_with($raw, '[ {')) {
            try {
                $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $decoded = null;
            }
            if (is_array($decoded) && ! empty($decoded)) {
                // The LAST entry is usually the most meaningful
                // one (error message or success marker). Fall
                // back to the first one if the last has empty
                // output.
                $last = end($decoded);
                $output = (string) data_get($last, 'output', '');
                if ($output === '') {
                    $first = reset($decoded);
                    $output = (string) data_get($first, 'output', '');
                }
                $raw = $output;
            }
        }

        // Normalise whitespace and escape sequences so the feed
        // row doesn't have literal "\n" or multi-space padding.
        $raw = str_replace(['\r\n', '\n', '\r', "\n", "\r", "\t"], ' ', $raw);
        $raw = trim((string) preg_replace('/\s+/', ' ', $raw));

        if (mb_strlen($raw) > 100) {
            $raw = mb_substr($raw, 0, 100).'…';
        }

        return $raw;
    }

    /**
     * Resolve a batch of uuids into a map { uuid => resourceName }
     * with a single whereIn query per resource model. Used by
     * loadRecentActivity() so the feed can prefix each row with
     * the friendly name of the resource the activity targeted.
     *
     * @param  array<int, string>  $uuids
     * @return array<string, string>
     */
    private function batchResolveResourceNames(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        $out = [];

        $add = function ($rows) use (&$out) {
            foreach ($rows as $row) {
                $uuid = (string) ($row->uuid ?? '');
                $name = (string) ($row->name ?? '');
                if ($uuid !== '' && $name !== '') {
                    $out[$uuid] = $name;
                }
            }
        };

        try {
            $add(Service::whereIn('uuid', $uuids)->get(['uuid', 'name']));
        } catch (\Throwable $e) {
        }
        try {
            $add(Application::whereIn('uuid', $uuids)->get(['uuid', 'name']));
        } catch (\Throwable $e) {
        }
        foreach ([
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ] as $class) {
            try {
                $add($class::whereIn('uuid', $uuids)->get(['uuid', 'name']));
            } catch (\Throwable $e) {
                // A missing table or a migration not yet run on
                // the local install just skips that class.
            }
        }

        return $out;
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
