<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Collects a live snapshot of one server's vital signs for the
 * "Monitor" page.
 *
 * Design goals:
 *   1. ONE SSH round trip per server per poll. Running seven
 *      separate `instant_remote_process` calls (one for CPU, one
 *      for free, one for df, etc.) is slow because every call
 *      pays the SSH handshake. We concatenate every probe into a
 *      single `bash -c '...'` that emits `KEY=VALUE` lines we
 *      parse in PHP.
 *
 *   2. Redis-cached with a 7-second TTL. The Livewire page polls
 *      every 10 seconds, so a 7-second cache lets a manual
 *      refresh hit fresh data (since the user clicks faster than
 *      the next scheduled poll) while still collapsing duplicate
 *      calls when two Livewire round-trips arrive back-to-back.
 *
 *   3. Tolerant of missing binaries. The collector does NOT
 *      assume the host has `mpstat`, `iostat`, `htop` or any
 *      non-POSIX tool. Every command falls back to plain
 *      coreutils (`top`, `free`, `df`, `uptime`, `docker`).
 *
 *   4. Never throws — if the SSH call fails or the server is
 *      unreachable, we return a structured payload with
 *      `online => false` and `error` populated, and the UI
 *      renders an "Unreachable" state instead of crashing.
 */
class MonitorMetricsCollector
{
    /**
     * Cache TTL for each server snapshot. Short enough that a
     * manual refresh still feels live, long enough to absorb
     * Livewire's retry storms and avoid hammering the host on
     * every poll tick.
     */
    public const CACHE_TTL_SECONDS = 7;

    /**
     * Collect everything the Monitor page needs about a single
     * server in one call.
     *
     * @return array<string, mixed>
     */
    public function collect(Server $server, bool $forceRefresh = false): array
    {
        $cacheKey = $this->cacheKeyFor($server);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($server) {
            return $this->gather($server);
        });
    }

    public function cacheKeyFor(Server $server): string
    {
        return 'monitor:server:'.$server->id.':snapshot';
    }

    /**
     * Invalidate the cached snapshot for a server. Called after
     * an action (restart proxy, stop service, etc.) so the next
     * poll shows the change immediately instead of the stale
     * pre-action values.
     */
    public function invalidate(Server $server): void
    {
        Cache::forget($this->cacheKeyFor($server));
    }

    /**
     * The actual SSH call + parsing. Kept separate from collect()
     * so the cache wrapper stays trivial.
     *
     * @return array<string, mixed>
     */
    private function gather(Server $server): array
    {
        $base = [
            'server_id' => $server->id,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'server_ip' => $server->ip,
            'online' => false,
            'error' => null,
            'collected_at' => now()->toIso8601String(),
            'cpu_percent' => null,
            'mem_percent' => null,
            'mem_used_human' => null,
            'mem_total_human' => null,
            'disk_percent' => null,
            'disk_used_human' => null,
            'disk_total_human' => null,
            'load_avg' => null,
            'uptime_human' => null,
            'containers_running' => null,
            'containers_total' => null,
            'proxy_type' => $server->proxyType(),
            'proxy_status' => (string) (data_get($server->proxy, 'status') ?? 'unknown'),
        ];

        if (! $server->isFunctional()) {
            $base['error'] = 'Server no disponible (no pasa validateConnection).';

            return $base;
        }

        // Single concatenated probe. Each echo emits a key=value
        // line the parser below picks up. If any sub-command
        // fails the line is still emitted with an empty value,
        // so the parser's defaults kick in.
        $probe = <<<'BASH'
            CPU_PCT=$(top -bn1 2>/dev/null | awk '/Cpu\(s\)/ {gsub("%","",$0); for(i=1;i<=NF;i++){if($i ~ /id,?$/){idle=$(i-1);break}}; if(idle=="") idle=100; printf "%.1f", 100-idle}' 2>/dev/null)
            MEM_INFO=$(free 2>/dev/null | awk '/Mem:/ {total=$2; used=$3; if(total>0){printf "%.1f %s %s", used/total*100, used, total}}')
            MEM_HUMAN=$(free -h 2>/dev/null | awk '/Mem:/ {print $3" "$2}')
            DISK_PCT=$(df / --output=pcent 2>/dev/null | tr -cd 0-9)
            DISK_HUMAN=$(df -h / 2>/dev/null | awk 'NR==2 {print $3" "$2}')
            LOAD=$(uptime 2>/dev/null | sed -E 's/.*load average[s]?: //' | awk '{print $1}' | tr -d ',')
            UPTIME=$(uptime -p 2>/dev/null || uptime 2>/dev/null | awk -F'up ' '{print $2}' | awk -F', [0-9]+ user' '{print $1}')
            CONT_RUNNING=$(docker ps -q 2>/dev/null | wc -l)
            CONT_TOTAL=$(docker ps -aq 2>/dev/null | wc -l)
            echo "CPU_PCT=${CPU_PCT}"
            echo "MEM_PCT=$(echo $MEM_INFO | awk '{print $1}')"
            echo "MEM_HUMAN=${MEM_HUMAN}"
            echo "DISK_PCT=${DISK_PCT}"
            echo "DISK_HUMAN=${DISK_HUMAN}"
            echo "LOAD=${LOAD}"
            echo "UPTIME=${UPTIME}"
            echo "CONT_RUNNING=${CONT_RUNNING}"
            echo "CONT_TOTAL=${CONT_TOTAL}"
        BASH;

        $raw = null;
        try {
            $raw = instant_remote_process([$probe], $server, false);
        } catch (\Throwable $e) {
            $base['error'] = 'SSH fallido: '.mb_substr($e->getMessage(), 0, 200);

            return $base;
        }

        if (! is_string($raw) || trim($raw) === '') {
            $base['error'] = 'Sin datos del probe (SSH devolvió vacío).';

            return $base;
        }

        $parsed = $this->parseKeyValues($raw);

        $base['online'] = true;
        $base['cpu_percent'] = $this->toFloat($parsed['CPU_PCT'] ?? null);
        $base['mem_percent'] = $this->toFloat($parsed['MEM_PCT'] ?? null);

        $memHuman = trim((string) ($parsed['MEM_HUMAN'] ?? ''));
        if ($memHuman !== '') {
            $parts = preg_split('/\s+/', $memHuman);
            $base['mem_used_human'] = $parts[0] ?? null;
            $base['mem_total_human'] = $parts[1] ?? null;
        }

        $base['disk_percent'] = $this->toFloat($parsed['DISK_PCT'] ?? null);
        $diskHuman = trim((string) ($parsed['DISK_HUMAN'] ?? ''));
        if ($diskHuman !== '') {
            $parts = preg_split('/\s+/', $diskHuman);
            $base['disk_used_human'] = $parts[0] ?? null;
            $base['disk_total_human'] = $parts[1] ?? null;
        }

        $base['load_avg'] = $this->toFloat($parsed['LOAD'] ?? null);
        $base['uptime_human'] = trim((string) ($parsed['UPTIME'] ?? '')) ?: null;
        $base['containers_running'] = $this->toInt($parsed['CONT_RUNNING'] ?? null);
        $base['containers_total'] = $this->toInt($parsed['CONT_TOTAL'] ?? null);

        return $base;
    }

    /**
     * Parse a multi-line KEY=VALUE blob into an associative array.
     * Tolerates extra whitespace and ignores lines without `=`.
     *
     * @return array<string, string>
     */
    private function parseKeyValues(string $raw): array
    {
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }

        return $out;
    }

    private function toFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        $clean = str_replace(',', '.', (string) $v);
        if (! is_numeric($clean)) {
            return null;
        }

        return round((float) $clean, 1);
    }

    private function toInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric((string) $v)) {
            return null;
        }

        return (int) $v;
    }
}
