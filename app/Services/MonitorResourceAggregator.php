<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Walks a list of servers and produces a unified, UI-ready list
 * of every resource Coolify manages across them: applications,
 * services and standalone databases.
 *
 * The output is the shape the Monitor page and the Alerts
 * dropdown both consume. Having a single normaliser means both
 * code paths always agree on what "Findpartners" looks like and
 * what buttons are available for it.
 *
 * Each normalised entry contains:
 *   - id            int primary key of the underlying model
 *   - uuid          string uuid for routing
 *   - type          'application' | 'service' | 'database'
 *   - kind_label    short human label ("WordPress", "Laravel",
 *                    "MariaDB", etc.) — best effort, falls back
 *                    to a generic label
 *   - name          display name shown in the UI
 *   - status        the colon-format status from Coolify
 *                    ("running:healthy", "degraded:unhealthy")
 *   - severity      'ok' | 'warning' | 'critical' — a
 *                    three-level bucket derived from status,
 *                    used to drive colours and alert counts
 *   - server_id     numeric id of the owning server
 *   - server_name   for the "which server is this on" column
 *   - project_name  human label for breadcrumb context
 *   - environment_name
 *   - last_online_at            iso timestamp if present
 *   - last_online_human         "hace 3 min" or null
 *   - url                       link to the resource config page
 *   - is_stopped                true when status is exited
 *
 * The aggregator does NOT hit the live Docker daemon — it reads
 * the last-known status from each model's status column, which
 * is kept up to date by Coolify's own ServerCheckJob /
 * PushServerUpdateJob pipelines. That gives a near-real-time
 * view without paying an SSH round-trip per resource.
 */
class MonitorResourceAggregator
{
    /**
     * Bucket a colon-format status string into one of three
     * severity buckets for alert/display purposes.
     *
     *   critical: the user needs to act NOW (crashed, dead,
     *             degraded, running but unhealthy, restarting
     *             loop)
     *   warning:  something is transitioning but not yet failed
     *             (starting, paused)
     *   ok:       fully running and healthy, or intentionally
     *             stopped (exited with no restart count)
     *
     * The "exited" bucket is considered OK because a resource
     * the user has explicitly stopped shouldn't light up the
     * alerts dropdown.
     */
    public static function severityOf(string $status): string
    {
        if (str_contains($status, 'degraded') || str_contains($status, 'unhealthy') || str_contains($status, 'dead') || str_contains($status, 'restarting')) {
            return 'critical';
        }
        if (str_contains($status, 'starting') || str_contains($status, 'paused')) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Collect every resource the given servers manage and
     * return it as a single normalised collection ordered by
     * severity (critical first) and then by name.
     *
     * @param  Collection<int, Server>  $servers
     * @return Collection<int, array<string, mixed>>
     */
    public function collectForServers(Collection $servers): Collection
    {
        $out = collect();

        foreach ($servers as $server) {
            if (! $server instanceof Server) {
                continue;
            }

            foreach ($server->applications() as $app) {
                $out->push($this->normaliseApplication($app, $server));
            }
            foreach ($server->databases() as $db) {
                $out->push($this->normaliseDatabase($db, $server));
            }
            foreach ($server->services()->get() as $svc) {
                $out->push($this->normaliseService($svc, $server));
            }
        }

        // Order: critical first, then warning, then ok; within
        // a bucket, sort by name alphabetically.
        $weight = ['critical' => 0, 'warning' => 1, 'ok' => 2];

        return $out->sortBy(function ($item) use ($weight) {
            return [
                $weight[$item['severity']] ?? 99,
                mb_strtolower((string) $item['name']),
            ];
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseApplication(Application $app, Server $server): array
    {
        $status = (string) ($app->status ?? 'exited');
        $severity = self::severityOf($status);
        [$lastIso, $lastHuman] = $this->safeTimestamp($app->last_online_at);

        return [
            'id' => $app->id,
            'uuid' => (string) $app->uuid,
            'type' => 'application',
            'kind_label' => $this->applicationKindLabel($app),
            'name' => (string) ($app->name ?? 'application-'.$app->id),
            'status' => $status,
            'severity' => $severity,
            'server_id' => $server->id,
            'server_name' => (string) $server->name,
            'project_name' => (string) data_get($app, 'environment.project.name', ''),
            'environment_name' => (string) data_get($app, 'environment.name', ''),
            'last_online_at' => $lastIso,
            'last_online_human' => $lastHuman,
            'url' => $this->applicationUrl($app),
            'web_url' => $this->firstFqdnUrl((string) ($app->fqdn ?? '')),
            'is_stopped' => str_contains($status, 'exited'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseDatabase($db, Server $server): array
    {
        $status = (string) ($db->status ?? 'exited');
        $severity = self::severityOf($status);
        [$lastIso, $lastHuman] = $this->safeTimestamp(data_get($db, 'last_online_at'));

        return [
            'id' => $db->id,
            'uuid' => (string) $db->uuid,
            'type' => 'database',
            'kind_label' => $this->databaseKindLabel($db),
            'name' => (string) ($db->name ?? 'database-'.$db->id),
            'status' => $status,
            'severity' => $severity,
            'server_id' => $server->id,
            'server_name' => (string) $server->name,
            'project_name' => (string) data_get($db, 'environment.project.name', ''),
            'environment_name' => (string) data_get($db, 'environment.name', ''),
            'last_online_at' => $lastIso,
            'last_online_human' => $lastHuman,
            'url' => $this->databaseUrl($db),
            // Databases have no public URL — the "Ver web"
            // button is hidden for them in the view.
            'web_url' => null,
            'is_stopped' => str_contains($status, 'exited'),
        ];
    }

    /**
     * Defensive timestamp coercion. Not every model in the fork
     * casts last_online_at to datetime (Application notably does
     * NOT cast it), so reading $model->last_online_at can return
     * a raw string. Calling ->diffForHumans() on that string blows
     * up the whole page with "Call to a member function on string".
     *
     * Returns [ISO8601 string or null, human-readable relative
     * label or null]. Never throws — any parse failure returns
     * [null, null] so the caller can safely render "—".
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function safeTimestamp(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        try {
            $carbon = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);

            return [$carbon->toIso8601String(), $carbon->diffForHumans()];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseService(Service $svc, Server $server): array
    {
        $status = (string) ($svc->status ?? 'exited');
        $severity = self::severityOf($status);

        // Services don't own a top-level fqdn — each
        // ServiceApplication inside the compose stack can have
        // its own. Pick the first ServiceApplication with a
        // non-empty fqdn so the "Ver web" button lands on the
        // visible site (mirrors the catalog UI).
        $webUrl = null;
        foreach ($svc->applications as $svcApp) {
            $fqdn = (string) ($svcApp->fqdn ?? '');
            if ($fqdn !== '') {
                $webUrl = $this->firstFqdnUrl($fqdn);
                if ($webUrl !== null) {
                    break;
                }
            }
        }

        return [
            'id' => $svc->id,
            'uuid' => (string) $svc->uuid,
            'type' => 'service',
            'kind_label' => $this->serviceKindLabel($svc),
            'name' => (string) ($svc->name ?? 'service-'.$svc->id),
            'status' => $status,
            'severity' => $severity,
            'server_id' => $server->id,
            'server_name' => (string) $server->name,
            'project_name' => (string) data_get($svc, 'environment.project.name', ''),
            'environment_name' => (string) data_get($svc, 'environment.name', ''),
            'last_online_at' => null,
            'last_online_human' => null,
            'url' => $this->serviceUrl($svc),
            'web_url' => $webUrl,
            'is_stopped' => str_contains($status, 'exited'),
        ];
    }

    /**
     * Turn the `fqdn` DB column (a single URL, a comma-separated
     * list of URLs, or bare host with no scheme) into the first
     * usable HTTPS link for the "Ver web" button. Returns null
     * when the value is blank or unparseable. Defaults to https
     * to avoid mixed-content warnings when clicked from an
     * https-served Coolify UI.
     */
    private function firstFqdnUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $first = trim(explode(',', $raw)[0]);
        if ($first === '') {
            return null;
        }
        if (! str_starts_with($first, 'http://') && ! str_starts_with($first, 'https://')) {
            return 'https://'.$first;
        }

        return $first;
    }

    private function applicationKindLabel(Application $app): string
    {
        $bp = strtolower((string) ($app->build_pack ?? ''));
        if ($bp === 'dockercompose') {
            return 'Compose';
        }
        if ($bp === 'dockerfile') {
            return 'Dockerfile';
        }
        if ($bp === 'dockerimage') {
            return 'Docker Image';
        }
        if ($bp === 'static') {
            return 'Static';
        }
        if ($bp === 'nixpacks') {
            return 'Nixpacks';
        }

        return 'App';
    }

    private function databaseKindLabel($db): string
    {
        $class = class_basename($db);
        $map = [
            'StandalonePostgresql' => 'PostgreSQL',
            'StandaloneMysql' => 'MySQL',
            'StandaloneMariadb' => 'MariaDB',
            'StandaloneMongodb' => 'MongoDB',
            'StandaloneRedis' => 'Redis',
            'StandaloneKeydb' => 'KeyDB',
            'StandaloneDragonfly' => 'Dragonfly',
            'StandaloneClickhouse' => 'Clickhouse',
        ];

        return $map[$class] ?? 'Database';
    }

    private function serviceKindLabel(Service $svc): string
    {
        $compose = (string) data_get($svc, 'docker_compose_raw', '');
        if (str_contains(strtolower($compose), 'wordpress')) {
            return 'WordPress';
        }
        if (str_contains($compose, 'SERVICE_GITHUB_REPO_URL')) {
            return 'Laravel';
        }

        return 'Service';
    }

    private function applicationUrl(Application $app): string
    {
        try {
            return route('project.application.configuration', [
                'project_uuid' => data_get($app, 'environment.project.uuid'),
                'environment_uuid' => data_get($app, 'environment.uuid'),
                'application_uuid' => $app->uuid,
            ]);
        } catch (\Throwable $e) {
            return '#';
        }
    }

    private function databaseUrl($db): string
    {
        try {
            return route('project.database.configuration', [
                'project_uuid' => data_get($db, 'environment.project.uuid'),
                'environment_uuid' => data_get($db, 'environment.uuid'),
                'database_uuid' => $db->uuid,
            ]);
        } catch (\Throwable $e) {
            return '#';
        }
    }

    private function serviceUrl(Service $svc): string
    {
        try {
            return route('project.service.configuration', [
                'project_uuid' => data_get($svc, 'environment.project.uuid'),
                'environment_uuid' => data_get($svc, 'environment.uuid'),
                'service_uuid' => $svc->uuid,
            ]);
        } catch (\Throwable $e) {
            return '#';
        }
    }
}
