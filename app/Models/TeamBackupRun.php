<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual row in the Backups history log.
 *
 * One row per destination per run. A chained "full run" writes two
 * rows (local + sftp) sharing a `batch_uuid`. Pending runs become
 * running, then completed / failed / skipped. Retention pruning
 * reads `created_at` to pick victims and `artifact_path` to know
 * what to delete.
 *
 * `stats_json` is a free-form bag used by the run summary to store
 * things like dumped database count, archived volume count, and
 * per-step exit codes. Keep it serializable.
 */
class TeamBackupRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'size_bytes' => 'integer',
        'stats_json' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Duration in seconds, or null if the run hasn't finished yet.
     * Used to compute ETA for the next run of the same destination.
     *
     * Carbon 3 returns a SIGNED float from diffInSeconds (positive
     * when the argument is in the future, negative when in the
     * past) so we wrap in abs() + int cast to guarantee a non-
     * negative integer. A previous bug here produced "23:59:15"
     * displayed durations for short runs because gmdate('H:i:s',
     * -45) wraps around to 23:59:15.
     */
    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) abs($this->started_at->diffInSeconds($this->finished_at));
    }

    /**
     * Average duration of the last N successful runs for a given
     * team + destination. Returns null on cold-start (no history)
     * so the UI can show "Primera ejecución, tiempo desconocido".
     */
    public static function averageDurationSeconds(int $teamId, string $destination, int $sample = 3): ?int
    {
        $runs = self::query()
            ->where('team_id', $teamId)
            ->where('destination', $destination)
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->limit($sample)
            ->get();

        if ($runs->isEmpty()) {
            return null;
        }

        $total = 0;
        $count = 0;
        foreach ($runs as $run) {
            $d = $run->durationSeconds();
            if ($d !== null && $d > 0) {
                $total += (int) $d;
                $count++;
            }
        }

        return $count > 0 ? (int) round($total / $count) : null;
    }
}
