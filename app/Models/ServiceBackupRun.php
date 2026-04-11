<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generation attempt of the per-service "Backup y descarga"
 * feature. Lives in its own table, separate from team_backup_runs,
 * because the lifecycle and the audience are different (see the
 * migration's docblock for the rationale).
 *
 * Status flow:
 *   pending → running → completed | failed
 *   completed → expired (after 30 min, when PruneExpiredServiceBackups
 *                        runs from the scheduler)
 *
 * The artifact (a .zip living on the Coolify host) is referenced
 * via artifact_path. The download controller translates that to
 * the in-container path with backup_host_to_container_path() so
 * the PHP process can read the file from inside the coolify web
 * container.
 *
 * Only WordPress services are ever backed up by this feature. The
 * gate lives in the Livewire component (ServiceBackupDownload) and
 * in the controller authorize step. The model itself does NOT
 * enforce that constraint — its only job is to be a typed row in
 * the history log.
 */
class ServiceBackupRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Duration in seconds, or null if the run hasn't finished yet.
     * Used by the UI to render "duración: 23s" / "duración: 1m 12s"
     * next to the row.
     *
     * Carbon 3 returns a SIGNED diff, so wrap in abs() the same way
     * TeamBackupRun::durationSeconds and the compression task
     * service do — every other place that has been bitten by this
     * pattern in the fork already paid the cost of figuring it out
     * the hard way.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) abs($this->started_at->diffInSeconds($this->finished_at));
    }

    /**
     * Whether this row is downloadable RIGHT NOW. The download
     * controller hits this as the final gate after the auth and
     * team-membership checks: a row that's expired (status flipped
     * by the prune) or hasn't finished yet returns false and the
     * request 404s.
     */
    public function isDownloadable(): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }
        if ($this->artifact_path === null || $this->artifact_path === '') {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
