<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class LaravelArtisan extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public array $laravelContainers = [];

    public ?int $selectedContainer = null;

    public bool $isLoadingCommands = false;

    /** @var array<int, array{name: string, description: string}> */
    public array $artisanCommands = [];

    public ?string $selectedCommand = null;

    public string $selectedCommandDescription = '';

    /** @var array<int, array{name: string, description: string}> */
    public array $filteredArtisanCommands = [];

    public bool $isLoadingHelp = false;

    public string $selectedCommandHelp = '';

    public string $output = '';

    public bool $isRunning = false;

    // Prevent the dropdown from reopening right after selecting an item.
    public bool $suppressCommandDropdown = false;

    /**
     * Returns the artisan sub-command token (the token after `artisan` if present).
     */
    private function getArtisanSubcommandToken(string $value): string
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));
        if ($tokens === []) {
            return '';
        }

        foreach ($tokens as $index => $token) {
            if (strtolower((string) $token) !== 'artisan') {
                continue;
            }

            return (string) ($tokens[$index + 1] ?? '');
        }

        return (string) ($tokens[0] ?? '');
    }

    /**
     * Hardcoded fallback list of the 10 most useful artisan commands for
     * day-to-day Laravel maintenance. Used both when the user first clicks
     * the input (so there is always something to click even before
     * `artisan list` has finished loading) and when the remote
     * `artisan list` call fails entirely (container not ready, json
     * format unsupported, output polluted with warnings, etc).
     *
     * @return array<int, array{name: string, description: string}>
     */
    private function defaultPopularCommands(): array
    {
        return [
            ['name' => 'migrate --force', 'description' => 'Aplicar migraciones pendientes en producción'],
            ['name' => 'migrate:status', 'description' => 'Ver el estado de las migraciones'],
            ['name' => 'optimize:clear', 'description' => 'Limpiar todas las cachés (config, route, view, cache, event)'],
            ['name' => 'cache:clear', 'description' => 'Limpiar la caché de aplicación'],
            ['name' => 'config:clear', 'description' => 'Limpiar la caché de configuración'],
            ['name' => 'route:clear', 'description' => 'Limpiar la caché de rutas'],
            ['name' => 'view:clear', 'description' => 'Limpiar las vistas compiladas'],
            ['name' => 'route:list', 'description' => 'Mostrar todas las rutas registradas'],
            ['name' => 'queue:restart', 'description' => 'Señalar a los workers que reinicien tras el próximo job'],
            ['name' => 'schedule:list', 'description' => 'Listar las tareas programadas del scheduler'],
            ['name' => 'db:seed --force', 'description' => 'Ejecutar los seeders de la base de datos'],
            ['name' => 'storage:link', 'description' => 'Crear el symlink public/storage → storage/app/public'],
            ['name' => 'about', 'description' => 'Mostrar información del entorno Laravel'],
            ['name' => 'env', 'description' => 'Mostrar el entorno actual (APP_ENV)'],
        ];
    }

    public function showPopularCommands(): void
    {
        if ($this->isLoadingCommands) {
            return;
        }

        if (trim((string) $this->selectedCommand) !== '') {
            $this->refreshCommandDropdown();

            return;
        }

        $popularNames = [
            'migrate',
            'migrate:status',
            'optimize:clear',
            'cache:clear',
            'config:clear',
            'route:clear',
            'view:clear',
            'route:list',
            'queue:restart',
            'schedule:list',
            'db:seed',
            'storage:link',
            'about',
            'env',
        ];

        // When artisan list already populated the dropdown, prefer its
        // entries (they have the real descriptions from the container
        // and will match whatever version of Laravel is deployed).
        if ($this->artisanCommands !== []) {
            $byName = collect($this->artisanCommands)->keyBy('name');
            $result = [];

            foreach ($popularNames as $name) {
                $cmd = $byName->get($name);
                if (is_array($cmd)) {
                    $result[] = $cmd;
                }
            }

            // Pad with any other commands until we have at least 10.
            if (count($result) < 10) {
                foreach ($this->artisanCommands as $cmd) {
                    if (count($result) >= 10) {
                        break;
                    }
                    if (in_array($cmd['name'] ?? '', $popularNames, true)) {
                        continue;
                    }
                    $result[] = $cmd;
                }
            }

            $this->filteredArtisanCommands = array_values(array_slice($result, 0, 10));

            return;
        }

        // Hardcoded fallback — always 10+ entries, always shows something
        // useful even if artisan list failed or has not run yet.
        $this->filteredArtisanCommands = array_values(array_slice($this->defaultPopularCommands(), 0, 10));
    }

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        // Team scoping: see LaravelManager::mount() for the rationale. A
        // bare Service::whereUuid() would load a service from any team
        // if the attacker guessed/obtained the UUID, and because the
        // ServicePolicy currently returns true for every action, the
        // authorize() call underneath would not catch it either.
        $this->service = Service::ownedByCurrentTeam()
            ->whereUuid(request()->route('service_uuid'))
            ->firstOrFail();
        $this->authorize('view', $this->service);
        $this->applications = $this->service->applications->sort();
        $this->detectLaravelContainers();

        // Auto-pick the first running Laravel container; UI no longer allows manual selection.
        if ($this->selectedContainer === null && ! empty($this->laravelContainers)) {
            $this->selectedContainer = (int) ($this->laravelContainers[0]['id'] ?? null);
        }

        if ($this->selectedContainer) {
            $this->loadArtisanCommands();
        }
    }

    public function detectLaravelContainers(): void
    {
        $this->laravelContainers = [];

        foreach ($this->applications as $application) {
            if (! $this->isLaravelContainer($application)) {
                continue;
            }

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

    public function isLaravelContainer($application): bool
    {
        // Primary heuristic (cheap): image/env suggests Laravel.
        $image = strtolower($application->image ?? '');
        $looksLikeLaravel = str_contains($image, 'laravel') || str_contains($image, 'php');

        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            $key = strtoupper($envVar->key ?? '');
            if (str_contains($key, 'LARAVEL') || str_contains($key, 'APP_KEY') || str_contains($key, 'APP_ENV')) {
                $looksLikeLaravel = true;
                break;
            }
        }

        if (! $looksLikeLaravel) {
            return false;
        }

        // Strong check: artisan must exist in the container.
        if (! str($application->status)->contains('running')) {
            return false;
        }

        $server = $application->service->server;
        $containerName = $application->name.'-'.$this->service->uuid;
        $escapedContainer = escapeshellarg($containerName);

        $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/artisan && echo found || echo notfound'";
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        $output = trim(instant_remote_process([$command], $server, false) ?? '');

        return $output === 'found';
    }

    private function getSelectedContainerContext(): ?array
    {
        if (! $this->selectedContainer) {
            return null;
        }

        $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainer);
        if (! $container) {
            return null;
        }

        $application = $container['application'] ?? $this->applications->find($container['id']);
        if (! $application || ! str($application->status)->contains('running')) {
            return null;
        }

        return [
            'container' => $container,
            'application' => $application,
            'server' => $application->service->server,
            'escapedContainer' => escapeshellarg($container['container_name']),
        ];
    }

    public function loadArtisanCommands(): void
    {
        $this->isLoadingCommands = true;
        $this->artisanCommands = [];
        $this->selectedCommand = '';
        $this->selectedCommandDescription = '';
        $this->selectedCommandHelp = '';
        $this->filteredArtisanCommands = [];

        try {
            $context = $this->getSelectedContainerContext();
            if (! $context) {
                $this->dispatch('error', 'Container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            // Prefer json output (easier to parse); fallback to plain output if unsupported.
            $command = "docker exec {$escapedContainer} php /var/www/html/artisan list --format=json";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $raw = (string) (instant_remote_process([$command], $server, false) ?? '');
            $raw = trim($raw);

            $commands = $this->parseArtisanListOutput($raw);
            $this->artisanCommands = $commands;

            if ($this->artisanCommands !== []) {
                // Leave the input empty; dropdown will populate when the user types.
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading artisan commands: '.$e->getMessage());
        } finally {
            $this->isLoadingCommands = false;
        }
    }

    private function refreshCommandDropdown(): void
    {
        $value = trim((string) $this->selectedCommand);
        if ($value === '') {
            $this->filteredArtisanCommands = [];

            return;
        }

        $subcommand = $this->getArtisanSubcommandToken($value);
        if ($subcommand === '') {
            $this->filteredArtisanCommands = [];

            return;
        }

        // Prefer the real commands loaded from the container; fall back to
        // the hardcoded popular list if `artisan list` has not populated
        // the array (container still warming up, json flag unsupported,
        // output polluted with warnings, etc.). This guarantees the
        // autocomplete works regardless of remote state.
        $haystack = $this->artisanCommands !== []
            ? $this->artisanCommands
            : $this->defaultPopularCommands();

        $q = strtolower($subcommand);
        $startsWith = array_values(array_filter(
            $haystack,
            fn (array $cmd) => str_starts_with(strtolower((string) ($cmd['name'] ?? '')), $q)
        ));

        $containsElsewhere = array_values(array_filter(
            $haystack,
            fn (array $cmd) => ! str_starts_with(strtolower((string) ($cmd['name'] ?? '')), $q)
                && str_contains(strtolower((string) ($cmd['name'] ?? '')), $q)
        ));

        // Order: exact prefix matches first, then "contains" matches.
        $merged = array_merge($startsWith, $containsElsewhere);
        $this->filteredArtisanCommands = array_values(array_slice($merged, 0, 10));
    }

    /**
     * @return array<int, array{name: string, description: string}>
     */
    private function parseArtisanListOutput(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = null;
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
        }

        if (is_array($decoded)) {
            $list = $decoded['commands'] ?? $decoded['data'] ?? $decoded;
            if (is_array($list)) {
                $result = [];
                foreach ($list as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? $item['command'] ?? '');
                    $description = (string) ($item['description'] ?? $item['help'] ?? '');
                    if ($name !== '') {
                        $result[] = ['name' => $name, 'description' => $description];
                    }
                }

                return $result;
            }
        }

        // Plain output fallback: lines like "about  Display basic information about your application."
        $result = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/^([a-zA-Z0-9:\-]+)\s{2,}(.+)$/', $line, $m)) {
                continue;
            }

            $result[] = [
                'name' => (string) $m[1],
                'description' => trim((string) $m[2]),
            ];
        }

        return $result;
    }

    public function loadHelp(): void
    {
        if (! $this->selectedCommand) {
            $this->selectedCommandHelp = '';
            return;
        }

        $this->isLoadingHelp = true;
        $this->selectedCommandHelp = '';

        try {
            $context = $this->getSelectedContainerContext();
            if (! $context) {
                $this->dispatch('error', 'Container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            $helpCommand = "docker exec {$escapedContainer} php /var/www/html/artisan help ".escapeshellarg($this->selectedCommand);
            if ($server->isNonRoot()) {
                $helpCommand = "sudo {$helpCommand}";
            }

            $this->selectedCommandHelp = (string) (instant_remote_process([$helpCommand], $server, false) ?? '');
        } catch (\Throwable $e) {
            $this->selectedCommandHelp = 'Error loading help: '.$e->getMessage();
        } finally {
            $this->isLoadingHelp = false;
        }
    }

    /**
     * Look up a command description across both the real artisan list and
     * the hardcoded popular-commands fallback. Matches exact name first,
     * then by the leading token of the name (so "migrate --force" in the
     * fallback still resolves when the user has "migrate" typed).
     */
    private function resolveCommandDescription(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $lookup = function (array $haystack, string $needle): ?string {
            foreach ($haystack as $cmd) {
                $cmdName = (string) ($cmd['name'] ?? '');
                if ($cmdName === $needle) {
                    return (string) ($cmd['description'] ?? '');
                }
                $leading = trim((string) (preg_split('/\s+/', $cmdName)[0] ?? ''));
                if ($leading === $needle) {
                    return (string) ($cmd['description'] ?? '');
                }
            }

            return null;
        };

        $desc = $lookup($this->artisanCommands, $name);
        if ($desc !== null) {
            return $desc;
        }

        return (string) ($lookup($this->defaultPopularCommands(), $name) ?? '');
    }

    public function selectCommand(string $command): void
    {
        $this->suppressCommandDropdown = true;
        $this->selectedCommand = $command;
        $name = trim((string) ($command ? preg_split('/\s+/', $command)[0] : ''));
        $this->selectedCommandDescription = $this->resolveCommandDescription($name);
        $this->filteredArtisanCommands = [];
    }

    public function updatedSelectedCommand(?string $value): void
    {
        if ($this->suppressCommandDropdown) {
            $this->suppressCommandDropdown = false;
            $this->filteredArtisanCommands = [];

            return;
        }

        if (! $value) {
            $this->selectedCommandDescription = '';
            $this->selectedCommandHelp = '';
            $this->filteredArtisanCommands = [];
            return;
        }

        $subcommand = $this->getArtisanSubcommandToken((string) $value);
        $this->selectedCommandDescription = $this->resolveCommandDescription($subcommand);
        $this->selectedCommandHelp = '';

        $this->refreshCommandDropdown();
    }

    public function run(): void
    {
        $this->validate([
            'selectedContainer' => 'required|integer',
            'selectedCommand' => ['required', 'string', 'max:300'],
        ]);

        $context = $this->getSelectedContainerContext();
        if (! $context) {
            $this->dispatch('error', 'Container not found or not running.');

            return;
        }

        $server = $context['server'];
        $escapedContainer = $context['escapedContainer'];

        $rawTokens = preg_split('/\s+/', trim((string) $this->selectedCommand)) ?: [];
        $rawTokens = array_values(array_filter($rawTokens, fn ($t) => $t !== ''));
        if ($rawTokens === []) {
            $this->dispatch('error', 'Command is empty.');

            return;
        }

        // Allow users to type either:
        // - "migrate --force"
        // - "php artisan migrate --force"
        // Strip leading "php artisan" when present.
        $artisanIndex = null;
        foreach ($rawTokens as $index => $token) {
            if (strtolower((string) $token) === 'artisan') {
                $artisanIndex = $index;
                break;
            }
        }

        $tokens = $artisanIndex !== null ? array_slice($rawTokens, $artisanIndex + 1) : $rawTokens;
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        if ($tokens === []) {
            $this->dispatch('error', 'Command is empty (no artisan sub-command found).');

            return;
        }

        // Disallow dangerous shell characters; docker exec will run the process directly,
        // but we still harden token inputs to avoid surprises.
        foreach ($tokens as $token) {
            if (preg_match('/[;&|`$<>\\\\]/', $token)) {
                $this->dispatch('error', 'Invalid characters in command.');

                return;
            }
        }

        // IMPORTANT: redirect stderr to stdout so we capture deprecations,
        // Laravel's exception renderer output (`-v`/`-vvv`) and any other
        // error channel that artisan writes to. Without `2>&1` a command
        // like `emails:fetch -vvv` that only prints to stderr ends up
        // showing an empty output box to the user even though it did run.
        // Wrapping in `sh -lc` also gives us a real login shell with a
        // working PATH, which matches how the scheduler actually runs
        // the command inside the container.
        $artisanArgs = implode(' ', array_map('escapeshellarg', $tokens));
        $innerScript = "php /var/www/html/artisan {$artisanArgs} 2>&1; echo \"__EXIT__=\$?\"";
        $command = "docker exec {$escapedContainer} sh -lc ".escapeshellarg($innerScript);
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        $this->isRunning = true;
        $this->output = '';

        try {
            $rawOutput = (string) (instant_remote_process([$command], $server, false) ?? '');

            // Extract and strip the sentinel exit code so the user only sees
            // what artisan actually printed. We still expose the exit code
            // at the end of the output for commands that returned non-zero
            // so it is obvious the command failed without scrolling up.
            $exitCode = null;
            if (preg_match('/__EXIT__=(\d+)\s*$/', $rawOutput, $m)) {
                $exitCode = (int) $m[1];
                $rawOutput = preg_replace('/__EXIT__=\d+\s*$/', '', $rawOutput) ?: $rawOutput;
            }

            $this->output = rtrim($rawOutput);

            if ($exitCode === null || $exitCode === 0) {
                $this->dispatch('success', 'Artisan command executed.');
            } else {
                if ($this->output === '') {
                    $this->output = "(el comando terminó con exit code {$exitCode} sin imprimir nada)";
                }
                $this->dispatch('error', "Artisan command exited with code {$exitCode}.");
            }
        } catch (\Throwable $e) {
            $this->output = $e->getMessage();
            $this->dispatch('error', 'Error running artisan: '.$e->getMessage());
        } finally {
            $this->isRunning = false;
            // Clear the input so the user can immediately type or click
            // another command without manually deleting the previous one.
            // Description and filtered-dropdown state are also reset so
            // nothing stale is shown under the input.
            $this->selectedCommand = '';
            $this->selectedCommandDescription = '';
            $this->selectedCommandHelp = '';
            $this->filteredArtisanCommands = [];
        }
    }

    public function render()
    {
        return view('livewire.project.service.laravel-artisan');
    }
}

// resync-marker 2026-04-08
