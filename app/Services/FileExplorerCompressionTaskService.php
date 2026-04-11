<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Centralised refresh + prune logic for the "Tareas en segundo plano"
 * dropdown that lives in the top navigation. Tasks are stored in cache
 * keyed by team id and surface in two places:
 *
 *   1. The FileExplorer Livewire component, when it mounts and at every
 *      user interaction with the explorer.
 *   2. The GET /compression-tasks JSON endpoint, polled every 4-20 s by
 *      the Alpine dropdown.
 *
 * Before this service existed only #1 ever called the refresh logic, so
 * the dropdown could keep showing "RUNNING" for hours after the actual
 * extraction had finished — until the user navigated back into the
 * explorer (or hit F5 hard enough to remount it). Centralising it here
 * lets the polling endpoint do the same kill -0 / log tail check on
 * every tick, so the UI converges to "completado" within one polling
 * cycle of the real-world finish.
 *
 * The service also annotates `finished_at` + `duration_seconds` when a
 * task transitions out of running, and the `pruneStale()` method drops
 * completed/failed rows that are older than the configured TTL (default
 * 5 minutes) so the dropdown self-cleans without forcing the user to
 * hit "Limpiar listas".
 */
class FileExplorerCompressionTaskService
{
    /**
     * How long completed/failed tasks linger in the dropdown before the
     * auto-prune step removes them. The user explicitly asked for 5 min
     * so the success state is visible long enough to read but the list
     * does not grow forever.
     */
    public const STALE_AFTER_SECONDS = 300;

    public function cacheKey(int|string $teamId): string
    {
        return 'file-explorer-compression-tasks:'.((string) $teamId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadFor(int|string $teamId): array
    {
        $cached = Cache::get($this->cacheKey($teamId), []);

        return is_array($cached) ? array_values($cached) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public function saveFor(int|string $teamId, array $tasks): void
    {
        // 24 h TTL — same as the original FileExplorer cache window. The
        // prune step normally takes them out far earlier; this is just
        // the safety net for tasks that never finish at all.
        Cache::put($this->cacheKey($teamId), array_values($tasks), now()->addDay());
    }

    /**
     * Run a refresh + prune cycle and return the resulting task list.
     * This is the single entry point used by both the Livewire
     * component and the polling endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    public function refreshFor(int|string $teamId): array
    {
        $tasks = $this->loadFor($teamId);
        if (empty($tasks)) {
            return [];
        }

        $refreshed = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $refreshed[] = $this->refreshSingle($task);
        }

        $pruned = $this->pruneStale($refreshed);

        $this->saveFor($teamId, $pruned);

        return $pruned;
    }

    /**
     * Drop completed/failed tasks whose finished_at is older than the
     * stale window. Tasks without a finished_at timestamp are kept
     * untouched (they're either still running, or they were marked
     * elsewhere outside this service and we don't know when).
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, array<string, mixed>>
     */
    public function pruneStale(array $tasks): array
    {
        $threshold = now()->subSeconds(self::STALE_AFTER_SECONDS);

        return array_values(array_filter($tasks, function ($task) use ($threshold) {
            if (! is_array($task)) {
                return false;
            }
            $status = (string) data_get($task, 'status', 'running');
            if (! in_array($status, ['completed', 'failed'], true)) {
                return true;
            }
            $finishedAt = data_get($task, 'finished_at');
            if (! is_string($finishedAt) || $finishedAt === '') {
                return true;
            }
            try {
                $finishedAtCarbon = Carbon::parse($finishedAt);
            } catch (\Throwable $e) {
                return true;
            }

            return $finishedAtCarbon->greaterThan($threshold);
        }));
    }

    /**
     * Inspect a single task and return the same array with `status`,
     * `last_message`, `finished_at` and `duration_seconds` updated to
     * reflect the real state of the underlying nohup process / archive
     * / log file inside the container.
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function refreshSingle(array $task): array
    {
        if (($task['status'] ?? '') !== 'running') {
            return $task;
        }

        $containerName = (string) data_get($task, 'container', '');
        $serverId = data_get($task, 'server_id');
        $server = is_numeric($serverId) ? Server::find((int) $serverId) : null;
        if (! $server instanceof Server || $containerName === '') {
            return $this->markFinished($task, 'failed', 'Server or container context no longer available.');
        }

        $escapedContainer = escapeshellarg($containerName);
        $taskType = (string) data_get($task, 'task_type', 'compression');
        $pid = data_get($task, 'pid');

        if (is_int($pid) && $pid > 0) {
            $runningCheck = "docker exec {$escapedContainer} sh -c 'kill -0 {$pid} >/dev/null 2>&1 && echo RUNNING || echo DONE'";
            if ($server->isNonRoot()) {
                $runningCheck = "sudo {$runningCheck}";
            }
            $runningResult = trim((string) (instant_remote_process([$runningCheck], $server, false) ?? ''));
            if ($runningResult === 'RUNNING') {
                if ($taskType === 'extraction') {
                    $logFile = (string) data_get($task, 'log_file', '');
                    if ($logFile !== '') {
                        $escapedLog = escapeshellarg($logFile);
                        $tailCommand = "docker exec {$escapedContainer} sh -c 'tail -n 1 {$escapedLog} 2>/dev/null'";
                        if ($server->isNonRoot()) {
                            $tailCommand = "sudo {$tailCommand}";
                        }
                        $liveTail = trim((string) (instant_remote_process([$tailCommand], $server, false) ?? ''));
                        $task['last_message'] = $liveTail !== '' ? 'Extrayendo: '.mb_substr($liveTail, 0, 240) : 'Extrayendo…';

                        return $task;
                    }
                }
                $task['last_message'] = 'Running...';

                return $task;
            }
        }

        if ($taskType === 'extraction') {
            $logFile = (string) data_get($task, 'log_file', '');
            if ($logFile !== '') {
                $escapedLog = escapeshellarg($logFile);
                $tailCommand = "docker exec {$escapedContainer} sh -c 'tail -n 50 {$escapedLog} 2>/dev/null'";
                if ($server->isNonRoot()) {
                    $tailCommand = "sudo {$tailCommand}";
                }
                $tailOutput = trim((string) (instant_remote_process([$tailCommand], $server, false) ?? ''));

                if (str_contains($tailOutput, 'EXTRACTION_SUCCESS')) {
                    $cleanup = "docker exec {$escapedContainer} sh -c 'rm -f {$escapedLog} 2>/dev/null || true'";
                    if ($server->isNonRoot()) {
                        $cleanup = "sudo {$cleanup}";
                    }
                    instant_remote_process([$cleanup], $server, false);

                    return $this->markFinished($task, 'completed', 'Archivo extraído correctamente.');
                }

                if (str_contains($tailOutput, 'TOOL_NOT_FOUND:')) {
                    return $this->markFinished($task, 'failed', 'La herramienta requerida no está en el contenedor (unzip/tar). Instálala manualmente o extrae desde la terminal.');
                }

                if (str_contains($tailOutput, 'EXTRACTION_FAILED')) {
                    $message = $tailOutput !== '' ? 'Extracción fallida: '.mb_substr($tailOutput, 0, 300) : 'Extracción fallida (sin detalles).';

                    return $this->markFinished($task, 'failed', $message);
                }

                $message = $tailOutput !== '' ? 'Extracción terminó sin marcador de éxito: '.mb_substr($tailOutput, 0, 300) : 'Extracción terminó sin output.';

                return $this->markFinished($task, 'failed', $message);
            }

            return $this->markFinished($task, 'failed', 'Extracción terminó sin log.');
        }

        $archivePath = (string) data_get($task, 'archive_path', '');
        if ($archivePath !== '') {
            $escapedArchive = escapeshellarg($archivePath);
            $existsCheck = "docker exec {$escapedContainer} sh -c 'test -f {$escapedArchive} && echo EXISTS || echo MISSING'";
            if ($server->isNonRoot()) {
                $existsCheck = "sudo {$existsCheck}";
            }
            $existsResult = trim((string) (instant_remote_process([$existsCheck], $server, false) ?? ''));
            if ($existsResult === 'EXISTS') {
                return $this->markFinished($task, 'completed', 'Archivo creado correctamente.');
            }
        }

        $logFile = (string) data_get($task, 'log_file', '');
        if ($logFile !== '') {
            $escapedLog = escapeshellarg($logFile);
            $tailCommand = "docker exec {$escapedContainer} sh -c 'tail -n 20 {$escapedLog} 2>/dev/null'";
            if ($server->isNonRoot()) {
                $tailCommand = "sudo {$tailCommand}";
            }
            $tailOutput = trim((string) (instant_remote_process([$tailCommand], $server, false) ?? ''));
            $message = $tailOutput !== '' ? mb_substr($tailOutput, 0, 300) : 'Compression finished without creating archive.';

            return $this->markFinished($task, 'failed', $message);
        }

        return $this->markFinished($task, 'failed', 'Compression finished without creating archive.');
    }

    /**
     * Stamp a task as completed/failed, capture finished_at and compute
     * duration_seconds from created_at when possible. The duration is
     * what the dropdown renders next to the relative time.
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function markFinished(array $task, string $status, string $message): array
    {
        $task['status'] = $status;
        $task['last_message'] = $message;
        $task['finished_at'] = now()->toDateTimeString();

        $createdAt = data_get($task, 'created_at');
        if (is_string($createdAt) && $createdAt !== '') {
            try {
                // Carbon 3 returns SIGNED diffs by default, so we use
                // abs() instead of relying on argument order. The
                // service has been bitten by this exact pattern in
                // TeamBackupRun before — keep it explicit so the
                // value is always non-negative.
                $task['duration_seconds'] = (int) abs(Carbon::parse($createdAt)->diffInSeconds(now(), false));
            } catch (\Throwable $e) {
                // Leave duration_seconds unset if the timestamp is unparseable.
            }
        }

        return $task;
    }
}
