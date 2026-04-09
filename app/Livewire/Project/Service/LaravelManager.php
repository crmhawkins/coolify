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
     * Editable draft of PHP ini values, indexed by POSITION inside
     * PHP_INI_EDITABLE_KEYS (not by ini key name). We deliberately use a
     * numeric index because Livewire treats dots in wire:model paths as
     * nested array access, so binding to "phpIniEditableValues.opcache.
     * memory_consumption" would try to resolve
     * $phpIniEditableValues['opcache']['memory_consumption'] instead of
     * the flat key 'opcache.memory_consumption'. The numeric-index form
     * sidesteps the problem entirely.
     *
     * @var array<int, string>
     */
    public array $phpIniEditableValues = [];

    public bool $isLoadingEnv = false;

    public bool $isLoadingPhpIni = false;

    public bool $isSavingPhpIni = false;

    /**
     * Detected type of the container selected in the PHP ini editor.
     * Drives which defaults are applied and whether we even render the
     * editor: "nginx" containers skip the editor entirely with an info
     * card, "phpmyadmin" and "laravel" both show the form but populate
     * the "Aplicar defaults" button with the appropriate preset.
     *
     * Possible values: '', 'nginx', 'phpmyadmin', 'laravel'.
     */
    public string $selectedContainerType = '';

    /**
     * Same idea as selectedContainerType but for the .env editor card.
     * Kept as a separate property so changing the ini selector does
     * not clobber the env selector state (and vice versa).
     */
    public string $selectedEnvContainerType = '';

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

    /**
     * Defaults tuned for a Laravel Rootkit production container:
     * generous upload/memory limits, aggressive OPcache with
     * revalidate_freq=0 so deploys pick up code changes instantly,
     * and a warm realpath cache for autoload performance.
     */
    public const PHP_INI_DEFAULTS_LARAVEL = [
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

    /**
     * Defaults tuned for a phpMyAdmin container: the main workloads are
     * huge SQL dump imports, long-running queries and large result set
     * exports — so we max out upload size, runtime and memory. OPcache
     * is kept smaller because phpMyAdmin is relatively static and does
     * not benefit from cache-everything, and revalidate_freq=60 is fine
     * because phpMyAdmin itself never changes at runtime.
     */
    public const PHP_INI_DEFAULTS_PHPMYADMIN = [
        'upload_max_filesize' => '512M',
        'post_max_size' => '512M',
        'max_execution_time' => '600',
        'max_input_time' => '600',
        'memory_limit' => '1024M',
        'max_input_vars' => '10000',
        'max_file_uploads' => '20',
        'opcache.memory_consumption' => '128',
        'opcache.max_accelerated_files' => '10000',
        'opcache.revalidate_freq' => '60',
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '600',
    ];

    /**
     * Per-directive validation bounds. Anchored to each key we expose in
     * the UI, NOT the raw php.ini universe — this is both a value-range
     * check AND an implicit whitelist: if a key is missing from this
     * table, validatePhpIniValue() rejects it outright.
     *
     * Size directives (upload_max_filesize, memory_limit, …) express
     * their min/max in BYTES so the parser and the comparison share the
     * same unit. Time directives are seconds, count directives are the
     * raw integer. `allow_minus_one`/`allow_zero` enable the special
     * sentinel values PHP accepts for "unlimited"/"disabled".
     *
     * @var array<string, array{min?: int, max?: int, unit: string, allow_minus_one?: bool, allow_zero?: bool}>
     */
    private const PHP_INI_BOUNDS = [
        // 1 MiB .. 5 GiB — covers typical dumps and rules out ridiculous values
        'upload_max_filesize' => ['min' => 1048576, 'max' => 5368709120, 'unit' => 'bytes'],
        'post_max_size' => ['min' => 1048576, 'max' => 5368709120, 'unit' => 'bytes'],
        // 64 MiB .. 8 GiB, -1 explicitly permitted because Laravel docs
        // recommend it for artisan long-running jobs.
        'memory_limit' => ['min' => 67108864, 'max' => 8589934592, 'unit' => 'bytes', 'allow_minus_one' => true],
        // 0..3600 seconds (0 = unlimited, per PHP docs).
        'max_execution_time' => ['min' => 0, 'max' => 3600, 'unit' => 'seconds', 'allow_zero' => true],
        // -1..3600 seconds (-1 = inherit from max_execution_time).
        'max_input_time' => ['min' => 0, 'max' => 3600, 'unit' => 'seconds', 'allow_zero' => true, 'allow_minus_one' => true],
        // 100..100k input vars; below 100 Laravel breaks, above 100k is a smell.
        'max_input_vars' => ['min' => 100, 'max' => 100000, 'unit' => 'count'],
        // 1..1000 files per request.
        'max_file_uploads' => ['min' => 1, 'max' => 1000, 'unit' => 'count'],
        // OPcache values are raw integers in MB / file count, not byte-prefixed.
        'opcache.memory_consumption' => ['min' => 32, 'max' => 4096, 'unit' => 'count'],
        'opcache.max_accelerated_files' => ['min' => 1000, 'max' => 1000000, 'unit' => 'count'],
        'opcache.revalidate_freq' => ['min' => 0, 'max' => 3600, 'unit' => 'seconds', 'allow_zero' => true],
        // 16 KiB .. 64 MiB for the realpath cache — anything below 16K is
        // useless, anything above 64M is wasteful on a PHP-FPM worker.
        'realpath_cache_size' => ['min' => 16384, 'max' => 67108864, 'unit' => 'bytes'],
        'realpath_cache_ttl' => ['min' => 0, 'max' => 86400, 'unit' => 'seconds', 'allow_zero' => true],
    ];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            // Team scoping: Service::ownedByCurrentTeam() filters by
            // environment.project.team.id so a user can never load a
            // service from another team even by knowing its UUID.
            // The authorize('view', ...) call below is defense in depth
            // in case the scope gets bypassed somewhere upstream.
            $this->service = Service::ownedByCurrentTeam()
                ->whereUuid(request()->route('service_uuid'))
                ->firstOrFail();
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

    /**
     * Containers the Laravel Manager UI is allowed to operate on. The
     * RootKit stack always has the same layout (laravel, nginx, mariadb,
     * phpmyadmin, optional scheduler/queue workers), so instead of the
     * old "image contains php" heuristic — which was too lax and matched
     * any PHP image in the universe — we combine a hard blacklist of
     * database/broker roles with an explicit whitelist for phpmyadmin
     * and nginx (both legitimately do not have an artisan file but still
     * show up in the UI, see the blade info cards).
     *
     * For anything else we REQUIRE the strong check (container is
     * running AND `/var/www/html/artisan` exists inside it). This
     * eliminates false positives like "random Symfony/WordPress
     * container with APP_ENV defined would match on env-vars alone".
     */
    public function isLaravelContainer($application): bool
    {
        $name = strtolower((string) ($application->name ?? ''));
        $image = strtolower((string) ($application->image ?? ''));

        // Hard blacklist: database/broker/cache containers are never
        // "Laravel" containers. This also spares us from firing a docker
        // exec into every mariadb/redis/… on every page load.
        foreach ([
            'mariadb', 'mysql', 'postgres', 'postgresql', 'redis',
            'mongo', 'mongodb', 'memcached', 'rabbitmq', 'kafka',
            'clickhouse', 'keydb', 'dragonfly', 'valkey',
            'elasticsearch', 'opensearch', 'minio', 'meilisearch',
            'typesense',
        ] as $blacklisted) {
            if (str_contains($name, $blacklisted) || str_contains($image, $blacklisted)) {
                return false;
            }
        }

        // Explicit whitelists: phpmyadmin and nginx are allowed in the
        // Manager UI even though they have no artisan. The blade shows
        // them info cards explaining why the .env editor is read-only
        // (nginx shares the laravel volume) or unavailable (phpmyadmin
        // has no Laravel .env at all), and phpmyadmin still needs the
        // php.ini editor for things like upload_max_filesize on big
        // SQL dump imports.
        if (str_contains($name, 'phpmyadmin') || str_contains($image, 'phpmyadmin')) {
            return true;
        }
        if (str_contains($name, 'nginx') || str_contains($image, 'nginx')) {
            return true;
        }

        // Strong check for everything else. Container must be running
        // and `/var/www/html/artisan` must exist inside it — no more
        // inferring Laravel-ness from the word "php" in the image or
        // APP_ENV in the env vars.
        if (! str($application->status)->contains('running')) {
            return false;
        }

        try {
            $server = $application->service->server;
            $containerName = $application->name.'-'.$this->service->uuid;
            $escapedContainer = escapeshellarg($containerName);
            $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/artisan && echo found || echo notfound'";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $output = trim(instant_remote_process([$command], $server, false) ?? '');

            return $output === 'found';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function loadEnvVariables()
    {
        if (! $this->selectedContainerForEnv) {
            return;
        }

        $this->isLoadingEnv = true;
        $this->envContent = '';
        $this->envFileExists = true;
        $this->selectedEnvContainerType = '';

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForEnv);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                $this->isLoadingEnv = false;

                return;
            }

            $this->selectedEnvContainerType = $this->determineContainerType($container);

            // Short-circuit for containers that are not the canonical
            // home of the Laravel .env file:
            //
            //  - nginx SHARES the same /var/www/html volume as laravel,
            //    so editing the file from nginx is functionally the
            //    same as editing it from laravel. We still stop here
            //    and point the user to the laravel container so there
            //    is a single canonical source of truth in the UI.
            //
            //  - phpmyadmin does NOT mount /var/www/html at all — its
            //    config lives in the docker-compose env vars, not in
            //    a Laravel-style .env file, so reading the path would
            //    just return "notfound" and render the (misleading)
            //    "Este proyecto no tiene .env" warning.
            if ($this->selectedEnvContainerType === 'nginx' || $this->selectedEnvContainerType === 'phpmyadmin') {
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

            // Tell the Alpine wrapper around the textarea to mark the
            // current value as the new baseline, so the "cambios sin
            // guardar" indicator disappears and the beforeunload
            // warning does not fire after a reload.
            $this->dispatch('env-reloaded');
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
            $this->safeRemoteCleanup($cleanCommand, $server, 'laravel-env tmp file');

            // Tell the Alpine wrapper to mark the current textarea value
            // as clean so the "cambios sin guardar" banner disappears and
            // the beforeunload warning stops firing.
            $this->dispatch('env-saved');
            $this->dispatch('success', 'Archivo .env guardado exitosamente.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error guardando archivo .env: '.$e->getMessage());
        }
    }

    /**
     * Best-effort cleanup of remote temporary files. Wraps the SSH call in
     * a try/catch + logs any failure via \Log::warning so /tmp leaks on the
     * Docker host become visible instead of silently piling up. Always
     * returns so the caller never has to worry about cleanup aborting the
     * happy path after a successful save.
     */
    private function safeRemoteCleanup(string $command, Server $server, string $context): void
    {
        try {
            instant_remote_process([$command], $server, true);
        } catch (\Throwable $e) {
            \Log::warning('LaravelManager: remote tmp cleanup failed', [
                'service_id' => $this->service->id ?? null,
                'server' => $server->ip ?? null,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort heuristic for detecting the flavour of a container
     * picked up by detectLaravelContainers(). Used by loadPhpIniSettings
     * to decide whether the ini editor even applies (nginx → no PHP)
     * and by applyRecommendedPhpDefaults to pick which defaults preset
     * to load (Laravel vs phpMyAdmin). We match on the image name
     * first, then fall back to the application's human name so
     * containers without a tagged image still get classified.
     *
     * @param  array<string, mixed>  $container
     */
    private function determineContainerType(array $container): string
    {
        $application = $container['application'] ?? null;
        $image = strtolower((string) ($application->image ?? ''));
        $name = strtolower((string) ($container['name'] ?? ''));

        // nginx is a pure web server container — no PHP interpreter,
        // the ini editor has nothing to do here.
        if (str_contains($image, 'nginx') || preg_match('/(^|[-_])nginx([-_]|$)/', $name)) {
            return 'nginx';
        }

        // phpMyAdmin runs PHP but for a totally different workload
        // (huge SQL imports, long queries, no artisan workers).
        if (str_contains($image, 'phpmyadmin') || preg_match('/(^|[-_])phpmyadmin([-_]|$)/', $name)) {
            return 'phpmyadmin';
        }

        // Everything else is treated as a Laravel-class container.
        return 'laravel';
    }

    public function loadPhpIniSettings()
    {
        if (! $this->selectedContainerForPhpIni) {
            return;
        }

        $this->isLoadingPhpIni = true;
        $this->phpIniSettings = [];
        $this->phpIniEditableValues = [];
        $this->selectedContainerType = '';

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

            $this->selectedContainerType = $this->determineContainerType($container);

            // Short-circuit for nginx: it has no PHP interpreter so
            // running `php -r ini_get(...)` would just error out. We
            // still finished the selection flow cleanly so the blade
            // can render the "no hace falta tocar nada aquí" card.
            if ($this->selectedContainerType === 'nginx') {
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

            // Populate the numeric-indexed editable draft. Index $i maps
            // to PHP_INI_EDITABLE_KEYS[$i] — the blade iterates the keys
            // list and uses the same index in wire:model to avoid the
            // dots-as-nested-array trap Livewire falls into.
            $this->phpIniEditableValues = [];
            foreach (self::PHP_INI_EDITABLE_KEYS as $i => $key) {
                $this->phpIniEditableValues[$i] = (string) ($this->phpIniSettings[$key] ?? '');
            }

            // Tell the Alpine wrapper around the ini form to mark the
            // current values as the new baseline; mirrors env-reloaded.
            $this->dispatch('phpini-reloaded');
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
        $defaults = match ($this->selectedContainerType) {
            'phpmyadmin' => self::PHP_INI_DEFAULTS_PHPMYADMIN,
            default => self::PHP_INI_DEFAULTS_LARAVEL,
        };

        $label = match ($this->selectedContainerType) {
            'phpmyadmin' => 'phpMyAdmin',
            default => 'Laravel Rootkit',
        };

        $this->phpIniEditableValues = [];
        foreach (self::PHP_INI_EDITABLE_KEYS as $i => $key) {
            $this->phpIniEditableValues[$i] = (string) ($defaults[$key] ?? '');
        }
        // Applying defaults should NOT reset the dirty flag — the user
        // has to click "Guardar" to persist. That's why we explicitly do
        // NOT dispatch phpini-reloaded here: the form is legitimately
        // dirty until save.
        $this->dispatch('success', "Defaults recomendados para {$label} aplicados. Pulsa \"Guardar\" para persistirlos.");
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

            // Validate every editable value against the per-directive bounds
            // table (PHP_INI_BOUNDS). Each failure short-circuits with a
            // Spanish error message that names the key, the bad value, and
            // the accepted range so the user can fix it without hunting
            // through the PHP manual. Empty string = "leave at php.ini
            // default", so we skip it (no override line written).
            $lines = [];
            $lines[] = '; Auto-generated by Coolify Laravel Manager';
            $lines[] = '; Editable from Project → Service → Laravel Manager';
            $lines[] = '; Values here override any earlier php.ini definitions';
            foreach (self::PHP_INI_EDITABLE_KEYS as $i => $key) {
                $value = trim((string) ($this->phpIniEditableValues[$i] ?? ''));
                if ($value === '') {
                    continue;
                }
                $error = $this->validatePhpIniValue($key, $value);
                if ($error !== null) {
                    $this->dispatch('error', $error);

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
            $this->safeRemoteCleanup($reloadCommand, $server, 'php-fpm SIGUSR2 reload');

            // Cleanup host + remote tmp copies.
            Storage::disk('local')->delete($tmpFilename);
            $cleanCommand = "rm -f {$escapedServerTmp}";
            if ($server->isNonRoot()) {
                $cleanCommand = "sudo {$cleanCommand}";
            }
            $this->safeRemoteCleanup($cleanCommand, $server, 'laravel-php-ini tmp file');

            $this->dispatch('phpini-saved');
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

    /**
     * Parse a PHP ini-style size/count value (like "512M", "4096K" or
     * "-1") into an integer in the directive's native unit. Returns null
     * if the input is syntactically malformed. Exposed as a private
     * instance method so unit tests and the validator can share the
     * exact same parsing logic without exporting state.
     *
     * Note: for directives whose `unit` is `count` or `seconds` we do
     * NOT apply the K/M/G suffix — PHP itself ignores those suffixes for
     * numeric directives like opcache.memory_consumption. The validator
     * below keys the unit off PHP_INI_BOUNDS so we do the right thing.
     */
    public static function parsePhpIniValue(string $value, string $unit = 'bytes'): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (! preg_match('/^(-?\d+)([KMGkmg])?$/', $trimmed, $m)) {
            return null;
        }
        $n = (int) $m[1];
        $suffix = strtoupper((string) ($m[2] ?? ''));

        if ($unit !== 'bytes' && $suffix !== '') {
            // K/M/G are only valid on byte-sized directives; reject them
            // for seconds and count directives so users don't set
            // opcache.memory_consumption="128M" thinking it means bytes.
            return null;
        }

        return match ($suffix) {
            'K' => $n * 1024,
            'M' => $n * 1024 * 1024,
            'G' => $n * 1024 * 1024 * 1024,
            default => $n,
        };
    }

    /**
     * Validate a single php.ini value against the per-directive bounds
     * table. Returns null on success or a user-facing Spanish error
     * message on failure. The returned string is safe to dispatch as an
     * error toast — it names the directive, the offending value and the
     * accepted range.
     */
    public static function validatePhpIniValue(string $key, string $raw): ?string
    {
        $bounds = self::PHP_INI_BOUNDS[$key] ?? null;
        if ($bounds === null) {
            // Whitelist enforcement: any key that isn't in PHP_INI_BOUNDS
            // is not writable through this editor even if it somehow
            // reaches this method. Belt-and-braces against a future
            // refactor adding a key to PHP_INI_EDITABLE_KEYS without
            // adding the matching bounds entry.
            return "La directiva \"{$key}\" no está permitida por el editor.";
        }

        $unit = (string) ($bounds['unit'] ?? 'bytes');
        $parsed = self::parsePhpIniValue($raw, $unit);
        if ($parsed === null) {
            $hint = $unit === 'bytes'
                ? 'Formato esperado: entero con sufijo opcional K/M/G (ej: 512M).'
                : 'Formato esperado: entero sin sufijo (ej: 300).';

            return "Valor inválido para {$key}: \"{$raw}\". {$hint}";
        }

        if ($parsed === -1) {
            return ($bounds['allow_minus_one'] ?? false)
                ? null
                : "El valor -1 no está permitido para {$key}.";
        }

        if ($parsed === 0 && ! ($bounds['allow_zero'] ?? false)) {
            return "El valor 0 no está permitido para {$key}.";
        }

        if ($parsed < 0) {
            return "Valor negativo no permitido para {$key}: \"{$raw}\".";
        }

        if (isset($bounds['min']) && $parsed < $bounds['min']) {
            $min = self::formatPhpIniBound($bounds['min'], $unit);

            return "Valor demasiado bajo para {$key}: \"{$raw}\". Mínimo permitido: {$min}.";
        }
        if (isset($bounds['max']) && $parsed > $bounds['max']) {
            $max = self::formatPhpIniBound($bounds['max'], $unit);

            return "Valor demasiado alto para {$key}: \"{$raw}\". Máximo permitido: {$max}.";
        }

        return null;
    }

    /**
     * Human-readable rendering of a validation bound for error messages.
     * Converts bytes back to the nearest K/M/G suffix so users see
     * "512M" instead of "536870912".
     */
    public static function formatPhpIniBound(int $bound, string $unit): string
    {
        if ($unit === 'bytes') {
            if ($bound >= 1024 * 1024 * 1024 && $bound % (1024 * 1024 * 1024) === 0) {
                return ((int) ($bound / 1024 / 1024 / 1024)).'G';
            }
            if ($bound >= 1024 * 1024 && $bound % (1024 * 1024) === 0) {
                return ((int) ($bound / 1024 / 1024)).'M';
            }
            if ($bound >= 1024 && $bound % 1024 === 0) {
                return ((int) ($bound / 1024)).'K';
            }

            return (string) $bound;
        }

        if ($unit === 'seconds') {
            return $bound.'s';
        }

        return (string) $bound;
    }

    public function render()
    {
        return view('livewire.project.service.laravel-manager');
    }
}
// resync-marker 2026-04-08
