<?php

namespace App\Livewire\Project\Service;

use App\Models\LocalFileVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class LaravelManager extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public $laravelContainers = [];

    public ?int $selectedContainerForEnv = null;

    public ?int $selectedContainerForPhpIni = null;

    public string $envContent = '';

    public bool $envFileExists = true;

    public array $phpIniSettings = [];

    /**
     * Editable draft of PHP ini values. Populated from phpIniSettings when
     * the user selects a container; mutated via wire:model on the form and
     * persisted to a custom .ini inside the container on save.
     *
     * @var array<string, string>
     */
    public array $phpIniEditableValues = [];

    public bool $isLoadingEnv = false;

    public bool $isLoadingPhpIni = false;

    public bool $isSavingPhpIni = false;

    /**
     * PHP ini keys the Laravel Manager exposes in the editor, in the order
     * they appear in the UI. Every key has a sensible default tuned for a
     * Laravel Rootkit production container (Apartamentos-class apps that
     * handle file uploads, email fetching and long-running artisan jobs).
     *
     * The defaults are deliberately generous — it is much easier to tune
     * down than to chase random 500s because the app ran out of memory
     * during a deploy or a bulk import.
     */
    public const PHP_INI_EDITABLE_KEYS = [
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_time',
        'memory_limit',
        'max_input_vars',
        'max_file_uploads',
        'opcache.memory_consumption',
        'opcache.max_accelerated_files',
        'opcache.revalidate_freq',
        'realpath_cache_size',
        'realpath_cache_ttl',
    ];

    public const PHP_INI_RECOMMENDED_DEFAULTS = [
        'upload_max_filesize' => '100M',
        'post_max_size' => '100M',
        'max_execution_time' => '300',
        'max_input_time' => '300',
        'memory_limit' => '512M',
        'max_input_vars' => '5000',
        'max_file_uploads' => '50',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '20000',
        'opcache.revalidate_freq' => '0',
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '600',
    ];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
            $this->authorize('view', $this->service);
            $this->applications = $this->service->applications->sort();
            $this->detectLaravelContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function detectLaravelContainers()
    {
        $this->laravelContainers = [];
        foreach ($this->applications as $application) {
            if ($this->isLaravelContainer($application)) {
                $containerName = $application->name.'-'.$this->service->uuid;
                $this->laravelContainers[] = [
                    'id' => $application->id,
                    'name' => $application->name,
                    'container_name' => $containerName,
                    'status' => $application->status,
                    'application' => $application,
                ];
            }
        }
    }

    public function isLaravelContainer($application): bool
    {
        // Check if image contains laravel or php
        $image = strtolower($application->image ?? '');
        if (str_contains($image, 'laravel') || str_contains($image, 'php')) {
            return true;
        }

        // Check environment variables
        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            $key = strtoupper($envVar->key ?? '');
            if (str_contains($key, 'LARAVEL') || str_contains($key, 'APP_KEY') || str_contains($key, 'APP_ENV')) {
                return true;
            }
        }

        // Check if artisan exists (if container is running)
        if (str($application->status)->contains('running')) {
            try {
                $server = $application->service->server;
                $containerName = $application->name.'-'.$this->service->uuid;
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/artisan && echo found || echo notfound'";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                $output = trim(instant_remote_process([$command], $server, false) ?? '');
                if ($output === 'found') {
                    return true;
                }
            } catch (\Throwable $e) {
                // Continue to next check
            }
        }

        return false;
    }

    public function loadEnvVariables()
    {
        if (! $this->selectedContainerForEnv) {
            return;
        }

        $this->isLoadingEnv = true;
        $this->envContent = '';
        $this->envFileExists = true;

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForEnv);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                $this->isLoadingEnv = false;

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                $this->isLoadingEnv = false;

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Try to read .env file
            $envPath = '/var/www/html/.env';
            $readCommand = "docker exec {$escapedContainer} sh -c 'test -f {$envPath} && cat {$envPath} || echo notfound'";
            if ($server->isNonRoot()) {
                $readCommand = "sudo {$readCommand}";
            }
            $envContent = instant_remote_process([$readCommand], $server, false) ?? '';

            if ($envContent === 'notfound' || empty($envContent)) {
                $this->envFileExists = false;
                $this->dispatch('warning', 'Este proyecto no tiene .env');

                return;
            }

            // Store the complete .env content
            $this->envContent = $envContent;

            $this->dispatch('success', 'Archivo .env cargado exitosamente.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading .env file: '.$e->getMessage());
        } finally {
            $this->isLoadingEnv = false;
        }
    }


    public function saveEnvFile()
    {
        if (! $this->selectedContainerForEnv) {
            return;
        }

        if (! $this->envFileExists) {
            $this->dispatch('warning', 'Este proyecto no tiene .env');

            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForEnv);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);
            $envPath = '/var/www/html/.env';

            // Write the complete .env content to container
            $tmpFilename = 'temp/'.uniqid('laravel-env-').'.env';
            Storage::disk('local')->put($tmpFilename, $this->envContent);
            $localTmpPath = Storage::disk('local')->path($tmpFilename);

            $serverTmpPath = '/tmp/'.basename($tmpFilename);
            instant_scp($localTmpPath, $serverTmpPath, $server);

            $escapedServerTmp = escapeshellarg($serverTmpPath);
            $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$envPath}";
            if ($server->isNonRoot()) {
                $copyCommand = "sudo {$copyCommand}";
            }
            instant_remote_process([$copyCommand], $server);

            Storage::disk('local')->delete($tmpFilename);
            $cleanCommand = "rm -f {$escapedServerTmp}";
            if ($server->isNonRoot()) {
                $cleanCommand = "sudo {$cleanCommand}";
            }
            instant_remote_process([$cleanCommand], $server, false);

            $this->dispatch('success', 'Archivo .env guardado exitosamente.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error guardando archivo .env: '.$e->getMessage());
        }
    }

    public function loadPhpIniSettings()
    {
        if (! $this->selectedContainerForPhpIni) {
            return;
        }

        $this->isLoadingPhpIni = true;
        $this->phpIniSettings = [];
        $this->phpIniEditableValues = [];

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForPhpIni);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                $this->isLoadingPhpIni = false;

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                $this->isLoadingPhpIni = false;

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Fetch every editable key in a single docker exec using PHP's
            // own serializer so we don't do 12 round-trips per reload. The
            // output is a newline-separated list of key=value pairs, which
            // we then split and map back into $phpIniSettings.
            $phpLookup = 'foreach (['
                .implode(',', array_map(fn ($k) => "'".addslashes($k)."'", self::PHP_INI_EDITABLE_KEYS))
                .'] as $k) { echo $k.\'=\'.ini_get($k).PHP_EOL; }';
            $command = "docker exec {$escapedContainer} php -r ".escapeshellarg($phpLookup);
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $raw = (string) (instant_remote_process([$command], $server, false) ?? '');
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || ! str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (in_array($key, self::PHP_INI_EDITABLE_KEYS, true)) {
                    $this->phpIniSettings[$key] = $value !== '' ? $value : '';
                }
            }

            // Make sure every editable key has an entry, even if PHP did
            // not return a value (unknown key on this PHP build, etc.).
            foreach (self::PHP_INI_EDITABLE_KEYS as $key) {
                if (! array_key_exists($key, $this->phpIniSettings)) {
                    $this->phpIniSettings[$key] = '';
                }
            }

            $this->phpIniEditableValues = $this->phpIniSettings;

            $this->dispatch('success', 'PHP settings loaded successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading PHP settings: '.$e->getMessage());
        } finally {
            $this->isLoadingPhpIni = false;
        }
    }

    /**
     * Fills the editable form with the recommended defaults for a
     * Laravel Rootkit container without persisting anything. The user
     * still has to click "Guardar" so a misclick on "Aplicar defaults"
     * does not overwrite production values silently.
     */
    public function applyRecommendedPhpDefaults(): void
    {
        $this->phpIniEditableValues = self::PHP_INI_RECOMMENDED_DEFAULTS;
        $this->dispatch('success', 'Defaults recomendados aplicados. Pulsa "Guardar" para persistirlos.');
    }

    /**
     * Writes the current editable values to a dedicated .ini file inside
     * the selected Laravel container under conf.d/ so PHP picks them up
     * without us having to touch the vendor-provided php.ini. After the
     * write we attempt a graceful PHP-FPM reload (SIGUSR2) and fall back
     * to asking the user to restart the container if that fails.
     */
    public function savePhpIniSettings(): void
    {
        if (! $this->selectedContainerForPhpIni) {
            return;
        }

        $this->isSavingPhpIni = true;

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForPhpIni);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Validate + normalise each editable value. The allow-list
            // matches PHP ini size syntax (digits with optional K/M/G
            // suffix) OR a plain integer. Anything else is rejected and
            // we bail with a clear error so the user can fix the input.
            $lines = [];
            $lines[] = '; Auto-generated by Coolify Laravel Manager';
            $lines[] = '; Editable from Project → Service → Laravel Manager';
            $lines[] = '; Values here override any earlier php.ini definitions';
            foreach (self::PHP_INI_EDITABLE_KEYS as $key) {
                $value = trim((string) ($this->phpIniEditableValues[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (! preg_match('/^\d+([KMG]|k|m|g)?$/', $value)) {
                    $this->dispatch('error', "Valor inválido para {$key}: \"{$value}\". Usa un número entero con sufijo opcional K/M/G (ej: 512M).");

                    return;
                }
                $lines[] = "{$key} = {$value}";
            }

            $iniContent = implode("\n", $lines)."\n";

            // Stage the ini content on the host, scp to the remote server,
            // then docker cp it into the container. Using docker cp is
            // safer than `echo > file` because it handles multi-line
            // content without quoting nightmares and preserves permissions.
            $tmpFilename = 'temp/'.uniqid('laravel-php-ini-').'.ini';
            Storage::disk('local')->put($tmpFilename, $iniContent);
            $localTmpPath = Storage::disk('local')->path($tmpFilename);

            $serverTmpPath = '/tmp/'.basename($tmpFilename);
            instant_scp($localTmpPath, $serverTmpPath, $server);

            $containerIniPath = '/usr/local/etc/php/conf.d/zzz-coolify-laravel-manager.ini';
            $escapedServerTmp = escapeshellarg($serverTmpPath);
            $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$containerIniPath}";
            if ($server->isNonRoot()) {
                $copyCommand = "sudo {$copyCommand}";
            }
            instant_remote_process([$copyCommand], $server);

            // Best-effort graceful reload of PHP-FPM. SIGUSR2 tells FPM to
            // reload its config without dropping in-flight requests. If
            // the container uses a different process manager or pkill is
            // missing, the command fails silently and the user just has
            // to restart the container manually.
            $reloadCommand = "docker exec {$escapedContainer} sh -lc 'pkill -USR2 php-fpm 2>/dev/null || pkill -USR2 php 2>/dev/null || true'";
            if ($server->isNonRoot()) {
                $reloadCommand = "sudo {$reloadCommand}";
            }
            instant_remote_process([$reloadCommand], $server, false);

            // Cleanup host + remote tmp copies.
            Storage::disk('local')->delete($tmpFilename);
            $cleanCommand = "rm -f {$escapedServerTmp}";
            if ($server->isNonRoot()) {
                $cleanCommand = "sudo {$cleanCommand}";
            }
            instant_remote_process([$cleanCommand], $server, false);

            $this->dispatch('success', 'Configuración PHP guardada. Si no se ve reflejada, reinicia el contenedor.');

            // Reload so the form reflects whatever PHP actually accepted
            // (e.g. a value might get truncated or sanitised by PHP).
            $this->loadPhpIniSettings();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error guardando configuración PHP: '.$e->getMessage());
        } finally {
            $this->isSavingPhpIni = false;
        }
    }

    public function render()
    {
        return view('livewire.project.service.laravel-manager');
    }
}
// resync-marker 2026-04-08
