<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-team configuration row for the "Backups" sidebar feature.
 *
 * One row per team. Holds both destinations (local + sftp), their
 * cron schedules, retention counts, scope presets, and the encrypted
 * SFTP credentials. Nothing in here is shared across teams — the
 * whole backup feature is team-scoped to match Coolify's tenancy
 * model.
 *
 * All sensitive fields (password, private key, passphrase) use the
 * native `encrypted` cast so Laravel handles AES round-trip via
 * APP_KEY automatically. Never log these fields.
 */
class TeamBackupSetting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'local_enabled' => 'boolean',
        'sftp_enabled' => 'boolean',
        'notify_on_failure' => 'boolean',
        'notify_on_success' => 'boolean',
        'notification_emails' => 'array',
        'local_retention' => 'integer',
        'sftp_retention' => 'integer',
        'sftp_port' => 'integer',
        'sftp_password' => 'encrypted',
        'sftp_private_key' => 'encrypted',
        'sftp_private_key_passphrase' => 'encrypted',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Default recipients for backup failure emails when a team has
     * not yet configured any. Matches the initial ask from the
     * user who commissioned the feature; the UI lets them edit.
     */
    public static function defaultNotificationEmails(): array
    {
        return ['dani.mefle@hawkins.es', 'ivan@hawkins.es'];
    }

    /**
     * Lazy-load or create the config row for a team. The creator path
     * seeds the defaults (enabled local, disabled sftp, Friday 22:00
     * cron, hawkins email addresses).
     */
    public static function forTeam(int $teamId): self
    {
        $setting = self::firstOrNew(['team_id' => $teamId]);
        if (! $setting->exists) {
            $setting->local_enabled = true;
            $setting->local_cron = '0 22 * * 5';
            $setting->local_retention = 4;
            $setting->local_scope = 'full';
            $setting->local_file_mode = 'persistent';
            $setting->local_path = '/data/coolify/backups/custom';
            $setting->sftp_enabled = false;
            $setting->sftp_retention = 8;
            $setting->sftp_port = 22;
            $setting->sftp_remote_path = '/backups/coolify';
            $setting->sftp_auth_method = 'password';
            $setting->notify_on_failure = true;
            $setting->notify_on_success = false;
            $setting->notification_emails = self::defaultNotificationEmails();
            $setting->save();
        }

        return $setting;
    }
}
