<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Produces the payload the Alerts bell dropdown consumes.
 *
 * Polls the MonitorResourceAggregator for the servers owned by
 * the current team and filters down to the rows with severity
 * != ok. The resulting payload is cached briefly so the bell's
 * polling loop (every 10-30 seconds) doesn't hammer the DB.
 *
 * Counted as an alert (severity matches operator expectations
 * confirmed in the spec — see the 5A+5C decision in the
 * planning chat):
 *   - exited                 (not counted — intentional stop)
 *   - running:healthy        (not counted — all good)
 *   - running:unhealthy      (not counted — healthcheck noisy)
 *   - running:unknown        (not counted — just means no
 *                              healthcheck defined)
 *   - starting:unknown       (warning — transitional)
 *   - degraded:unhealthy     (critical)
 *   - restarting:unknown     (critical)
 *   - dead / removing        (critical)
 *
 * Operators only want to be paged when something is actually
 * wrong OR when something is stuck in a transition. Everything
 * else is noise.
 */
class MonitorAlertsService
{
    public const CACHE_TTL_SECONDS = 10;

    public function __construct(private MonitorResourceAggregator $aggregator) {}

    /**
     * @param  Collection<int, Server>  $servers
     * @return array<string, mixed>
     */
    public function summaryFor(Collection $servers, ?int $teamId = null): array
    {
        $cacheKey = 'monitor:alerts:'.($teamId ?? 'anon');

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($servers) {
            return $this->compute($servers);
        });
    }

    public function invalidate(?int $teamId = null): void
    {
        if ($teamId === null) {
            return;
        }
        Cache::forget('monitor:alerts:'.$teamId);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @return array<string, mixed>
     */
    private function compute(Collection $servers): array
    {
        $resources = $this->aggregator->collectForServers($servers);

        $alerts = $resources->filter(function ($row) {
            if (! is_array($row)) {
                return false;
            }
            $severity = $row['severity'] ?? 'ok';
            if ($severity === 'ok') {
                return false;
            }

            return true;
        })->values();

        $critical = $alerts->where('severity', 'critical')->count();
        $warning = $alerts->where('severity', 'warning')->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'total' => $alerts->count(),
            'critical' => $critical,
            'warning' => $warning,
            'items' => $alerts->take(20)->map(function ($row) {
                return [
                    'id' => $row['id'],
                    'type' => $row['type'],
                    'uuid' => $row['uuid'],
                    'name' => $row['name'],
                    'kind_label' => $row['kind_label'],
                    'status' => $row['status'],
                    'severity' => $row['severity'],
                    'server_name' => $row['server_name'],
                    'project_name' => $row['project_name'],
                    'url' => $row['url'],
                    'last_online_human' => $row['last_online_human'],
                ];
            })->values()->all(),
        ];
    }
}
