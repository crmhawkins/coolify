<?php

namespace App\Actions\TeamBackup;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Cheap, best-effort size estimator for a team backup.
 *
 * We don't actually run the dumps — that would defeat the purpose
 * of an "estimation" box in the UI. Instead:
 *
 *  - For database containers we query `docker exec du -sb` against
 *    the data directory of each container (fast, single command)
 *    and sum the raw bytes. This overestimates because the dumped
 *    SQL will compress to roughly 10-30% of the raw on-disk size,
 *    but overestimation is the right side to err on for a "do I
 *    have enough disk space?" check.
 *
 *  - For persistent volumes we `du -sb` the host path directly.
 *
 *  - For full-container mode we `du -sb /var/www/html` inside each
 *    container.
 *
 * Every `du` is run with `2>/dev/null || echo 0` so a missing or
 * permission-denied path just contributes 0 rather than blowing
 * up the whole estimate.
 */
class EstimateBackupSize
{
    use AsAction;

    public function handle(int $teamId, string $scope = 'full', string $fileMode = 'persistent'): array
    {
        $totalBytes = 0;
        $dbBytes = 0;
        $fileBytes = 0;
        $dbCount = 0;
        $fileCount = 0;

        if ($scope === 'full' || $scope === 'databases') {
            $this->sumStandaloneDatabases($teamId, $dbBytes, $dbCount);
            $this->sumServiceDatabases($teamId, $dbBytes, $dbCount);
        }

        if ($scope === 'full' || $scope === 'files') {
            if ($fileMode === 'persistent') {
                $this->sumPersistentVolumes($teamId, $fileBytes, $fileCount);
            } else {
                $this->sumContainerWebroots($teamId, $fileBytes, $fileCount);
            }
        }

        $totalBytes = $dbBytes + $fileBytes;

        return [
            'total_bytes' => $totalBytes,
            'db_bytes' => $dbBytes,
            'file_bytes' => $fileBytes,
            'db_count' => $dbCount,
            'file_count' => $fileCount,
        ];
    }

    private function sumStandaloneDatabases(int $teamId, int &$total, int &$count): void
    {
        $classes = [
            StandalonePostgresql::class => '/var/lib/postgresql/data',
            StandaloneMysql::class => '/var/lib/mysql',
            StandaloneMariadb::class => '/var/lib/mysql',
            StandaloneMongodb::class => '/data/db',
            StandaloneRedis::class => '/data',
            StandaloneKeydb::class => '/data',
            StandaloneDragonfly::class => '/data',
        ];
        foreach ($classes as $class => $path) {
            $list = $class::whereHas('environment.project.team', fn ($q) => $q->where('id', $teamId))->get();
            foreach ($list as $db) {
                $server = $db->destination?->server;
                if (! $server) {
                    continue;
                }
                $cname = (string) $db->uuid;
                $bytes = (int) trim((string) instant_remote_process([
                    "docker exec {$cname} du -sb ".escapeshellarg($path)." 2>/dev/null | awk '{print \$1}' || echo 0",
                ], $server, throwError: false, timeout: 60));
                // Apply a rough compression factor of 0.25 so the
                // box reads closer to the real tarball size.
                $total += (int) round($bytes * 0.25);
                $count++;
            }
        }
    }

    private function sumServiceDatabases(int $teamId, int &$total, int &$count): void
    {
        foreach (ServiceDatabase::whereHas('service.environment.project.team', fn ($q) => $q->where('id', $teamId))->get() as $db) {
            $server = $db->service->server;
            if (! $server) {
                continue;
            }
            $cname = $db->name.'-'.$db->service->uuid;
            $type = strtolower((string) $db->databaseType());
            $path = match (true) {
                str_contains($type, 'postgres') => '/var/lib/postgresql/data',
                str_contains($type, 'mysql'), str_contains($type, 'mariadb') => '/var/lib/mysql',
                str_contains($type, 'mongo') => '/data/db',
                default => '/data',
            };
            $bytes = (int) trim((string) instant_remote_process([
                "docker exec {$cname} du -sb ".escapeshellarg($path)." 2>/dev/null | awk '{print \$1}' || echo 0",
            ], $server, throwError: false, timeout: 60));
            $total += (int) round($bytes * 0.25);
            $count++;
        }
    }

    private function sumPersistentVolumes(int $teamId, int &$total, int &$count): void
    {
        $applications = Application::whereHas('environment.project.team', fn ($q) => $q->where('id', $teamId))->get();
        $svcApps = ServiceApplication::whereHas('service.environment.project.team', fn ($q) => $q->where('id', $teamId))->get();
        foreach ([$applications, $svcApps] as $collection) {
            foreach ($collection as $resource) {
                $server = $resource->service?->server ?? $resource->destination?->server;
                if (! $server) {
                    continue;
                }
                $volumes = LocalPersistentVolume::where('resource_type', $resource::class)
                    ->where('resource_id', $resource->id)
                    ->get();
                foreach ($volumes as $vol) {
                    $hostPath = (string) $vol->host_path;
                    if ($hostPath === '') {
                        continue;
                    }
                    $bytes = (int) trim((string) instant_remote_process([
                        'du -sb '.escapeshellarg($hostPath)." 2>/dev/null | awk '{print \$1}' || echo 0",
                    ], $server, throwError: false, timeout: 60));
                    // tar.gz on binary assets lands around 50-90%
                    // of raw size; use 0.7 as an overshooting guess.
                    $total += (int) round($bytes * 0.7);
                    $count++;
                }
            }
        }
    }

    private function sumContainerWebroots(int $teamId, int &$total, int &$count): void
    {
        $svcApps = ServiceApplication::whereHas('service.environment.project.team', fn ($q) => $q->where('id', $teamId))->get();
        foreach ($svcApps as $svcApp) {
            $server = $svcApp->service->server;
            if (! $server) {
                continue;
            }
            $cname = $svcApp->name.'-'.$svcApp->service->uuid;
            $bytes = (int) trim((string) instant_remote_process([
                "docker exec {$cname} du -sb /var/www/html 2>/dev/null | awk '{print \$1}' || echo 0",
            ], $server, throwError: false, timeout: 120));
            $total += (int) round($bytes * 0.7);
            $count++;
        }
    }
}
