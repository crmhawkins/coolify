<?php

namespace App\Livewire\Backups;

use App\Actions\TeamBackup\EstimateBackupSize;
use App\Jobs\RunTeamBackupJob;
use App\Models\TeamBackupRun;
use App\Models\TeamBackupSetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use phpseclib3\Net\SFTP;

/**
 * Main page for the "Backups" sidebar feature.
 *
 * Layout: two tabs (Backup Local / Backup Servidor Hawkins) plus
 * a shared history log and a scheduler toggle that governs both.
 *
 * Writes straight to TeamBackupSetting; uses Livewire validation
 * attributes on scalar properties and custom rules for the JSON
 * recipient list. Dispatches RunTeamBackupJob for manual runs and
 * polls the runs table for live status updates.
 */
class Index extends Component
{
    public string $activeTab = 'local';

    public TeamBackupSetting $settings;

    // Local ----------------------------------------------------------
    public bool $local_enabled;

    #[Validate('required|string|max:64')]
    public string $local_cron;

    #[Validate('required|integer|min:1|max:60')]
    public int $local_retention;

    #[Validate('required|in:full,databases,files')]
    public string $local_scope;

    #[Validate('required|in:persistent,full-container')]
    public string $local_file_mode;

    #[Validate('required|string|max:255')]
    public string $local_path;

    // SFTP -----------------------------------------------------------
    public bool $sftp_enabled;

    #[Validate('required|integer|min:1|max:60')]
    public int $sftp_retention;

    #[Validate('nullable|string|max:255')]
    public ?string $sftp_host = null;

    #[Validate('required|integer|min:1|max:65535')]
    public int $sftp_port;

    #[Validate('nullable|string|max:120')]
    public ?string $sftp_username = null;

    #[Validate('nullable|string|max:255')]
    public ?string $sftp_remote_path = null;

    #[Validate('required|in:password,key')]
    public string $sftp_auth_method;

    #[Validate('nullable|string|max:2000')]
    public ?string $sftp_password = null;

    #[Validate('nullable|string|max:10000')]
    public ?string $sftp_private_key = null;

    #[Validate('nullable|string|max:255')]
    public ?string $sftp_private_key_passphrase = null;

    // Notifications --------------------------------------------------
    public bool $notify_on_failure;

    public bool $notify_on_success;

    /** @var array<int, string> */
    public array $notification_emails = [];

    public string $new_email = '';

    // Size estimation state ------------------------------------------
    public ?int $estimatedTotalBytes = null;

    public ?int $estimatedDbCount = null;

    public ?int $estimatedFileCount = null;

    public ?int $hostFreeBytes = null;

    public function mount(): void
    {
        if (auth()->user()?->isClient()) {
            abort(403, 'Los clientes no tienen acceso a la sección de Backups.');
        }

        $teamId = (int) (currentTeam()?->id ?? 0);
        $this->settings = TeamBackupSetting::forTeam($teamId);

        $this->local_enabled = (bool) $this->settings->local_enabled;
        $this->local_cron = (string) $this->settings->local_cron;
        $this->local_retention = (int) $this->settings->local_retention;
        $this->local_scope = (string) $this->settings->local_scope;
        $this->local_file_mode = (string) $this->settings->local_file_mode;
        $this->local_path = (string) $this->settings->local_path;

        $this->sftp_enabled = (bool) $this->settings->sftp_enabled;
        $this->sftp_retention = (int) $this->settings->sftp_retention;
        $this->sftp_host = $this->settings->sftp_host;
        $this->sftp_port = (int) $this->settings->sftp_port;
        $this->sftp_username = $this->settings->sftp_username;
        $this->sftp_remote_path = $this->settings->sftp_remote_path;
        $this->sftp_auth_method = (string) $this->settings->sftp_auth_method;
        // Never echo the decrypted password back into a form field
        // once it's been saved — we leave the input empty on mount
        // and only write it if the user types something new.
        $this->sftp_password = null;
        $this->sftp_private_key = null;
        $this->sftp_private_key_passphrase = null;

        $this->notify_on_failure = (bool) $this->settings->notify_on_failure;
        $this->notify_on_success = (bool) $this->settings->notify_on_success;
        $this->notification_emails = is_array($this->settings->notification_emails)
            ? array_values(array_filter($this->settings->notification_emails, fn ($e) => is_string($e) && $e !== ''))
            : TeamBackupSetting::defaultNotificationEmails();

        // Local disk free bytes — read once on mount so the "do I
        // have enough space?" box doesn't re-query on every poll.
        $this->hostFreeBytes = coolify_host_free_bytes(rtrim($this->local_path, '/') ?: '/');
    }

    public function render()
    {
        return view('livewire.backups.index');
    }

    // ---------------------------------------------------------------
    // Save handlers — split in two so the local tab and the sftp tab
    // don't cross-validate each other. Each dispatches a success or
    // error toast.
    // ---------------------------------------------------------------

    public function saveLocal(): void
    {
        $this->validateOnly('local_cron');
        $this->validateOnly('local_retention');
        $this->validateOnly('local_scope');
        $this->validateOnly('local_file_mode');
        $this->validateOnly('local_path');

        $this->settings->update([
            'local_enabled' => $this->local_enabled,
            'local_cron' => $this->local_cron,
            'local_retention' => $this->local_retention,
            'local_scope' => $this->local_scope,
            'local_file_mode' => $this->local_file_mode,
            'local_path' => $this->local_path,
        ]);

        $this->hostFreeBytes = coolify_host_free_bytes(rtrim($this->local_path, '/') ?: '/');
        $this->dispatch('success', 'Ajustes del backup local guardados.');
    }

    public function saveSftp(): void
    {
        $this->validateOnly('sftp_retention');
        $this->validateOnly('sftp_port');
        $this->validateOnly('sftp_auth_method');

        $data = [
            'sftp_enabled' => $this->sftp_enabled,
            'sftp_retention' => $this->sftp_retention,
            'sftp_host' => $this->sftp_host,
            'sftp_port' => $this->sftp_port,
            'sftp_username' => $this->sftp_username,
            'sftp_remote_path' => $this->sftp_remote_path ?: '/backups/coolify',
            'sftp_auth_method' => $this->sftp_auth_method,
        ];
        // Only overwrite credentials if the user actually typed
        // something — otherwise we preserve whatever is in the DB.
        if (filled($this->sftp_password)) {
            $data['sftp_password'] = $this->sftp_password;
        }
        if (filled($this->sftp_private_key)) {
            $data['sftp_private_key'] = $this->sftp_private_key;
        }
        if (filled($this->sftp_private_key_passphrase)) {
            $data['sftp_private_key_passphrase'] = $this->sftp_private_key_passphrase;
        }

        $this->settings->update($data);
        // Clear local form state so the password input stays empty.
        $this->sftp_password = null;
        $this->sftp_private_key = null;
        $this->sftp_private_key_passphrase = null;

        $this->dispatch('success', 'Ajustes del backup SFTP guardados.');
    }

    public function saveNotifications(): void
    {
        $emails = array_values(array_filter(
            array_map(fn ($e) => trim((string) $e), $this->notification_emails),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
        $this->settings->update([
            'notify_on_failure' => $this->notify_on_failure,
            'notify_on_success' => $this->notify_on_success,
            'notification_emails' => $emails,
        ]);
        $this->notification_emails = $emails;
        $this->dispatch('success', 'Notificaciones guardadas.');
    }

    public function addEmail(): void
    {
        $email = trim($this->new_email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->dispatch('error', 'Dirección de correo no válida.');

            return;
        }
        if (in_array($email, $this->notification_emails, true)) {
            $this->dispatch('warning', 'Ese correo ya está en la lista.');

            return;
        }
        $this->notification_emails[] = $email;
        $this->new_email = '';
        $this->saveNotifications();
    }

    public function removeEmail(string $email): void
    {
        $this->notification_emails = array_values(array_filter(
            $this->notification_emails,
            fn ($e) => $e !== $email
        ));
        $this->saveNotifications();
    }

    // ---------------------------------------------------------------
    // Estimation
    // ---------------------------------------------------------------

    public function estimate(): void
    {
        try {
            $teamId = (int) (currentTeam()?->id ?? 0);
            $result = EstimateBackupSize::run($teamId, $this->local_scope, $this->local_file_mode);
            $this->estimatedTotalBytes = (int) ($result['total_bytes'] ?? 0);
            $this->estimatedDbCount = (int) ($result['db_count'] ?? 0);
            $this->estimatedFileCount = (int) ($result['file_count'] ?? 0);
            $this->hostFreeBytes = coolify_host_free_bytes(rtrim($this->local_path, '/') ?: '/');
            $this->dispatch('success', 'Estimación actualizada.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'No se pudo estimar: '.mb_substr($e->getMessage(), 0, 300));
        }
    }

    // ---------------------------------------------------------------
    // Manual run trigger
    // ---------------------------------------------------------------

    /**
     * Manual run dispatcher. Accepts one of:
     *   - 'both'       → local backup and then SFTP upload
     *   - 'local-only' → only the local backup
     *   - 'sftp-only'  → re-upload the most recent completed local
     *                    tarball via SFTP (no redump)
     *
     * Rejects unknown modes and also blocks if there is already a
     * running/pending backup for the team, so two simultaneous
     * clicks can't corrupt each other's staging directories.
     */
    public function runNow(string $mode = 'both'): void
    {
        $validModes = ['both', 'local-only', 'sftp-only'];
        if (! in_array($mode, $validModes, true)) {
            $this->dispatch('error', 'Modo de ejecución no válido.');

            return;
        }

        $teamId = (int) (currentTeam()?->id ?? 0);

        // Only block against still-running work; completed/failed
        // rows don't block anything. The job itself will also
        // re-validate the preconditions for the selected mode.
        $existing = TeamBackupRun::query()
            ->where('team_id', $teamId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
        if ($existing) {
            $this->dispatch('warning', 'Ya hay un backup en marcha. Espera a que termine.');

            return;
        }

        // Quick preflight warnings for sftp-only so the user gets
        // immediate feedback instead of having to read the history
        // log a minute later.
        if ($mode === 'sftp-only') {
            if (! $this->settings->sftp_enabled) {
                $this->dispatch('error', 'Activa el SFTP antes de usar "Solo SFTP".');

                return;
            }
            $lastLocal = TeamBackupRun::query()
                ->where('team_id', $teamId)
                ->where('destination', 'local')
                ->where('status', 'completed')
                ->whereNotNull('artifact_path')
                ->latest('created_at')
                ->first();
            if (! $lastLocal || ! is_file((string) $lastLocal->artifact_path)) {
                $this->dispatch('error', 'No hay un tarball local previo para re-subir. Ejecuta primero un backup local.');

                return;
            }
        }
        if ($mode === 'local-only' && ! $this->settings->local_enabled) {
            $this->dispatch('error', 'Activa el backup local antes de usar "Solo local".');

            return;
        }

        RunTeamBackupJob::dispatch($teamId, $this->local_scope, 'manual', $mode);

        $label = match ($mode) {
            'local-only' => 'backup local',
            'sftp-only' => 'subida SFTP',
            default => 'backup completo (local + SFTP)',
        };
        $this->dispatch('success', "Encolado {$label}. El historial se refresca automáticamente.");
    }

    public function testSftpConnection(): void
    {
        // Save first so the credentials we're about to test match
        // what the job will actually use later.
        $this->saveSftp();
        $this->settings->refresh();

        if (! $this->settings->sftp_enabled) {
            $this->dispatch('error', 'Activa primero el SFTP y guarda.');

            return;
        }
        if (! $this->settings->sftp_host || ! $this->settings->sftp_username) {
            $this->dispatch('error', 'Host y usuario son obligatorios.');

            return;
        }
        try {
            $sftp = new SFTP((string) $this->settings->sftp_host, (int) $this->settings->sftp_port, 15);
            $user = (string) $this->settings->sftp_username;
            if ($this->settings->sftp_auth_method === 'key') {
                $raw = (string) $this->settings->sftp_private_key;
                if ($raw === '') {
                    throw new \RuntimeException('Clave privada vacía.');
                }
                $passphrase = (string) $this->settings->sftp_private_key_passphrase;
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($raw, $passphrase !== '' ? $passphrase : false);
                if (! $sftp->login($user, $key)) {
                    throw new \RuntimeException('Login rechazado por el servidor (clave SSH).');
                }
            } else {
                $password = (string) $this->settings->sftp_password;
                if ($password === '') {
                    throw new \RuntimeException('Contraseña vacía.');
                }
                if (! $sftp->login($user, $password)) {
                    throw new \RuntimeException('Login rechazado por el servidor (password).');
                }
            }
            // Try listing the remote dir to prove we have
            // read access. Failure here still counts as "login OK
            // but remote path missing" which is a soft warning.
            $remoteDir = (string) $this->settings->sftp_remote_path;
            $list = $sftp->nlist($remoteDir);
            if ($list === false) {
                $this->dispatch('warning', "Login OK, pero no se pudo leer la carpeta remota '{$remoteDir}'. Comprueba permisos o ruta.");

                return;
            }
            $this->dispatch('success', 'Conexión SFTP correcta.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'SFTP fallido: '.mb_substr($e->getMessage(), 0, 300));
        }
    }

    // ---------------------------------------------------------------
    // Computed: history + ETA
    // ---------------------------------------------------------------

    #[Computed]
    public function runs()
    {
        $teamId = (int) (currentTeam()?->id ?? 0);

        return TeamBackupRun::where('team_id', $teamId)
            ->latest('created_at')
            ->limit(25)
            ->get();
    }

    #[Computed]
    public function etaLocalSeconds(): ?int
    {
        return TeamBackupRun::averageDurationSeconds((int) (currentTeam()?->id ?? 0), 'local');
    }

    #[Computed]
    public function etaSftpSeconds(): ?int
    {
        return TeamBackupRun::averageDurationSeconds((int) (currentTeam()?->id ?? 0), 'sftp');
    }

    /**
     * Human-readable local timezone string for the scheduler label.
     * We read from Laravel's config('app.timezone') which Coolify
     * sets from the instance settings.
     */
    #[Computed]
    public function localTimezone(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }
}
