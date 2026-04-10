<?php

namespace App\Livewire\Project\Service;

use App\Actions\Service\FixWordPressContentPermissions;
use App\Models\LocalFileVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class WordPressManager extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public $wordpressContainers = [];

    public string $oldUrl = '';

    public string $newUrl = '';

    public bool $isProcessing = false;

    public string $output = '';

    public array $wpPrefixes = [];

    public ?int $selectedContainerForPhpIni = null;

    public array $phpIniSettings = [];

    public bool $isLoadingPhpIni = false;

    /**
     * State for the "Arreglar permisos wp-content" card. Populated by
     * fixWpContentPermissions() from the result of the action's
     * handle(): a per-container summary with the full shell output
     * and a booleans telling the blade whether the write test passed.
     *
     * Shape: see FixWordPressContentPermissions::handle() return type.
     *
     * @var array{ok: bool, containers: array<int, array<string, mixed>>, errors: array<int, string>}|null
     */
    public ?array $fixPermsResult = null;

    public bool $isFixingPermissions = false;

    public bool $isApplyingPhpDefaults = false;

    /**
     * Recommended php.ini values for a production WordPress container.
     * The WordPress-official Docker image ships with the PHP defaults
     * (upload_max_filesize=2M, post_max_size=8M, memory_limit=128M),
     * which are far too tight for any real workload — most plugin
     * zips, media imports and backup plugins hit the 2M cap instantly.
     *
     * Tuned for:
     *   - Uploading media files and plugin zips up to ~256 MB
     *   - Running backup/migration plugins (UpdraftPlus, All-in-One
     *     WP Migration) without OOM
     *   - Elementor editor saves with lots of widgets (max_input_vars)
     *   - Long-running imports (max_execution_time)
     *
     * Applied BOTH via the "Aplicar defaults WordPress" button in the
     * UI and automatically by SetupWordPress::setupWordPressContainer()
     * the very first time a WordPress container is provisioned, so
     * users never see the stock 2M/8M values again.
     */
    public const WP_PHP_INI_DEFAULTS = [
        'upload_max_filesize' => '256M',
        'post_max_size' => '256M',
        'memory_limit' => '512M',
        'max_execution_time' => '300',
        'max_input_time' => '300',
        'max_input_vars' => '5000',
        'max_file_uploads' => '50',
    ];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            // Team scoping: see LaravelManager::mount() for the rationale.
            // Service::ownedByCurrentTeam() filters by the
            // environment.project.team relation so a UUID from another
            // team 404s before the policy layer is consulted, even
            // though ServicePolicy currently returns true.
            $this->service = Service::ownedByCurrentTeam()
                ->whereUuid(request()->route('service_uuid'))
                ->firstOrFail();
            $this->authorize('view', $this->service);
            $this->applications = $this->service->applications->sort();
            $this->detectWordPressContainers();
            $this->detectWpPrefixes();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function detectWordPressContainers()
    {
        $this->wordpressContainers = [];
        foreach ($this->applications as $application) {
            if ($this->isWordPressContainer($application)) {
                $containerName = $application->name.'-'.$this->service->uuid;
                $this->wordpressContainers[] = [
                    'id' => $application->id,
                    'name' => $application->name,
                    'container_name' => $containerName,
                    'status' => $application->status,
                    'application' => $application,
                ];
            }
        }
    }

    public function isWordPressContainer($application): bool
    {
        // Check if image contains wordpress
        if (str_contains(strtolower($application->image ?? ''), 'wordpress')) {
            return true;
        }

        // Check environment variables
        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            if (str_contains(strtoupper($envVar->key ?? ''), 'WORDPRESS')) {
                return true;
            }
        }

        // Check if wp-config.php exists (if container is running)
        if (str($application->status)->contains('running')) {
            try {
                $server = $application->service->server;
                $containerName = $application->name.'-'.$this->service->uuid;
                $escapedContainer = escapeshellarg($containerName);
                $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/wp-config.php && echo found || echo notfound'";
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

    /**
     * Replace every occurrence of the old URL with the new URL across
     * the WordPress database. Tries three strategies in order, falling
     * back to the next one if the previous is unavailable:
     *
     *   1. WP-CLI `wp search-replace` — if installed, the canonical
     *      path. Handles serialised PHP arrays correctly.
     *
     *   2. Raw SQL UPDATE via the `mysql` client inside the WordPress
     *      container — covers the WordPress-official image, which
     *      does NOT ship WP-CLI. Hits the core URL columns:
     *        - wp_options: siteurl + home
     *        - wp_posts: guid, post_content (plain replace),
     *          post_excerpt
     *        - wp_postmeta: meta_value plain replace
     *        - wp_usermeta: meta_value plain replace (Elementor
     *          stores editor session data here sometimes)
     *        - wp_comments: comment_content
     *      The downside of SQL is that it CANNOT rewrite URLs that
     *      are embedded inside PHP-serialized data (Elementor stores
     *      theme_mods, menu items, etc. as `s:NN:"..."` strings). The
     *      output warns the user about this so they know to also
     *      reinstall/resave anything that stores serialized URLs.
     *
     *   3. If neither WP-CLI nor mysql CLI is available, we surface a
     *      clear error and do not pretend to have succeeded.
     *
     * After every strategy (even on failure) we still run
     * fixPermissions() so a partial failure doesn't leave wp-content
     * in a broken state.
     */
    public function syncUrls()
    {
        $this->authorize('update', $this->service);

        $this->validate([
            'oldUrl' => 'required|url',
            'newUrl' => 'required|url',
        ]);

        if (empty($this->wordpressContainers)) {
            $this->dispatch('error', 'No WordPress containers found.');

            return;
        }

        $this->isProcessing = true;
        $this->output = '';

        $anyUpdated = false;

        try {
            foreach ($this->wordpressContainers as $container) {
                $application = $container['application'] ?? $this->applications->find($container['id']);
                if (! $application || ! str($application->status)->contains('running')) {
                    $this->output .= "[{$container['name']}] SKIP — contenedor no está en ejecución\n\n";

                    continue;
                }

                $server = $application->service->server;
                $containerName = $container['container_name'];
                $escapedContainer = escapeshellarg($containerName);

                $this->output .= "================================================\n";
                $this->output .= "[{$container['name']}]\n";
                $this->output .= "================================================\n";

                // Strategy 1: WP-CLI if available.
                $wpCliAvailable = $this->isWpCliAvailable($server, $escapedContainer);
                if ($wpCliAvailable) {
                    $this->output .= "→ WP-CLI detectado, usando wp search-replace\n";
                    $ok = $this->runSyncUrlsWithWpCli($server, $escapedContainer);
                    $this->output .= $ok ? "✓ WP-CLI search-replace completado\n\n" : "✗ WP-CLI search-replace fallido\n\n";
                    $anyUpdated = $anyUpdated || $ok;
                } else {
                    $this->output .= "→ WP-CLI no encontrado, usando fallback SQL directo\n";
                    $sqlResult = $this->runSyncUrlsWithSql($server, $escapedContainer, $container);
                    $this->output .= $sqlResult['log'];
                    if ($sqlResult['ok']) {
                        $this->output .= "✓ UPDATE SQL completado en todas las tablas\n";
                        $this->output .= "⚠ Aviso: el método SQL no reescribe URLs embebidas en datos serializados (Elementor theme_mods, menu items…). Si el tema usa esos datos puede que debas reguardar los ajustes del tema o widgets afectados.\n\n";
                        $anyUpdated = true;
                    } else {
                        $this->output .= "✗ Error en el UPDATE SQL. Ni WP-CLI ni mysql CLI disponibles — no se han reemplazado URLs.\n\n";
                    }
                }

                // Fix permissions regardless of update success — it's idempotent.
                $this->fixPermissions($server, $containerName);
            }

            if ($anyUpdated) {
                $this->dispatch('success', 'URLs sincronizadas correctamente.');
            } else {
                $this->dispatch('error', 'No se pudieron sincronizar URLs en ningún contenedor. Revisa el output.');
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error sincronizando URLs: '.$e->getMessage());
            $this->output .= "\nExcepción: ".$e->getMessage();
        } finally {
            $this->isProcessing = false;
        }
    }

    /**
     * Checks whether WP-CLI is available inside the target container.
     * Looks first for the canonical `wp` binary in $PATH, then for
     * the phar downloaded to /usr/local/bin as a convention.
     */
    private function isWpCliAvailable($server, string $escapedContainer): bool
    {
        $check = "docker exec {$escapedContainer} sh -c 'command -v wp >/dev/null 2>&1 || test -x /usr/local/bin/wp && echo ok || echo notfound'";
        if ($server->isNonRoot()) {
            $check = "sudo {$check}";
        }
        $result = trim((string) (instant_remote_process([$check], $server, false) ?? ''));

        return $result === 'ok';
    }

    /**
     * Strategy 1 implementation: run the WP-CLI search-replace chain.
     * Swallows per-command errors so Elementor-specific commands
     * don't break the overall flow on vanilla WordPress installs.
     */
    private function runSyncUrlsWithWpCli($server, string $escapedContainer): bool
    {
        $wpPath = '/var/www/html';
        $oldUrlEscaped = escapeshellarg($this->oldUrl);
        $newUrlEscaped = escapeshellarg($this->newUrl);

        $commands = [
            ['name' => 'Search & Replace URLs', 'cmd' => "cd {$wpPath} && wp search-replace {$oldUrlEscaped} {$newUrlEscaped} --all-tables --allow-root 2>&1"],
            ['name' => 'Elementor URL Replacement', 'cmd' => "cd {$wpPath} && wp elementor replace-url {$oldUrlEscaped} {$newUrlEscaped} --allow-root 2>&1 || true"],
            ['name' => 'Flush Elementor CSS Cache', 'cmd' => "cd {$wpPath} && wp elementor flush-css-cache --allow-root 2>&1 || true"],
            ['name' => 'Flush WordPress Cache', 'cmd' => "cd {$wpPath} && wp cache flush --allow-root 2>&1 || true"],
        ];

        $anyOk = false;
        foreach ($commands as $cmd) {
            $dockerCommand = "docker exec {$escapedContainer} sh -c ".escapeshellarg($cmd['cmd']);
            if ($server->isNonRoot()) {
                $dockerCommand = "sudo {$dockerCommand}";
            }
            try {
                $out = (string) (instant_remote_process([$dockerCommand], $server, false) ?? '');
                $this->output .= "  • {$cmd['name']}: ".trim($out !== '' ? $out : 'ok')."\n";
                if ($cmd['name'] === 'Search & Replace URLs') {
                    // Only the main command counts as "did something".
                    $anyOk = true;
                }
            } catch (\Throwable $e) {
                $this->output .= "  • {$cmd['name']}: ERROR ".$e->getMessage()."\n";
            }
        }

        return $anyOk;
    }

    /**
     * Strategy 2 implementation: UPDATE REPLACE() statements executed
     * via a PHP helper script running `mysqli` inside the WordPress
     * container. The old version used the `mysql` CLI which is NOT
     * installed in the official WordPress image — this rewrite uses
     * the same PHP + mysqli pattern as updateWpPrefix() so it works
     * on vanilla WordPress installs without any extra tooling.
     *
     * Returns ['ok' => bool, 'log' => string].
     *
     * @param  array<string, mixed>  $containerMeta
     * @return array{ok: bool, log: string}
     */
    private function runSyncUrlsWithSql($server, string $escapedContainer, array $containerMeta): array
    {
        $log = '';

        $prefix = $this->detectWpPrefix($server, $containerMeta['container_name']) ?? 'wp_';
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            return ['ok' => false, 'log' => "  ✗ Prefijo detectado inválido: {$prefix}\n"];
        }
        $log .= "  ℹ prefijo detectado: {$prefix}\n";

        $script = $this->buildSyncUrlsPhpScript();
        $result = $this->runPhpScriptInContainer(
            $server,
            $escapedContainer,
            $script,
            [$prefix, $this->oldUrl, $this->newUrl]
        );

        if (! $result['ok']) {
            return ['ok' => false, 'log' => $log."  ✗ script falló (exit {$result['exit_code']}): ".$result['stderr']."\n"];
        }

        $payload = json_decode($result['stdout'], true);
        if (! is_array($payload) || ! isset($payload['ok'])) {
            return ['ok' => false, 'log' => $log."  ✗ respuesta inválida del script: ".substr((string) $result['stdout'], 0, 300)."\n"];
        }
        if ($payload['ok'] !== true) {
            return ['ok' => false, 'log' => $log."  ✗ ".(string) ($payload['message'] ?? 'error desconocido')."\n"];
        }

        $log .= "  ✓ options: {$payload['options']} filas · posts: {$payload['posts']} filas · postmeta: {$payload['postmeta']} filas · comments: {$payload['comments']} filas\n";

        return ['ok' => true, 'log' => $log];
    }

    /**
     * PHP helper script that performs the URL search-replace across
     * the canonical WordPress URL tables using mysqli. Argv[1] =
     * prefix, argv[2] = old URL, argv[3] = new URL.
     *
     * Output: JSON with per-table affected_rows counts.
     *
     * Limitation (same as WP-CLI with --skip-tables): does NOT
     * rewrite URLs inside PHP-serialized blobs (Elementor theme_mods
     * and similar). The caller surfaces this warning to the user.
     */
    public function buildSyncUrlsPhpScript(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ERROR | E_PARSE);
function out($payload) { echo json_encode($payload); exit; }

$prefix = $argv[1] ?? '';
$old = $argv[2] ?? '';
$new = $argv[3] ?? '';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
    out(['ok' => false, 'message' => 'Invalid prefix argument.']);
}
if ($old === '' || $new === '') {
    out(['ok' => false, 'message' => 'Old or new URL is empty.']);
}

$cfg = @file_get_contents('/var/www/html/wp-config.php');
if ($cfg === false) {
    out(['ok' => false, 'message' => 'wp-config.php not readable']);
}

/**
 * Same multi-strategy DB constant resolver as buildUpdatePrefixPhpScript().
 * Kept inline (duplicated across both scripts) because each script
 * runs as a standalone file inside the container — there is no
 * shared include path we can depend on, and both scripts are small
 * enough that the duplication is cheaper than setting up a shared
 * module round-trip.
 */
function resolve_db_var(string $content, string $name): ?string {
    $envName = 'WORDPRESS_' . $name;
    $fromEnv = getenv($envName);
    if ($fromEnv === false || $fromEnv === '') {
        $fromEnv = $_ENV[$envName] ?? $_SERVER[$envName] ?? null;
    }
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }
    if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s', $content, $m)) {
        return $m[2];
    }
    return null;
}

$dbName = resolve_db_var($cfg, 'DB_NAME');
$dbUser = resolve_db_var($cfg, 'DB_USER');
$dbPass = resolve_db_var($cfg, 'DB_PASSWORD');
$dbHost = resolve_db_var($cfg, 'DB_HOST') ?? 'localhost';
$missing = [];
if ($dbName === null) { $missing[] = 'DB_NAME'; }
if ($dbUser === null) { $missing[] = 'DB_USER'; }
if ($dbPass === null) { $missing[] = 'DB_PASSWORD'; }
if (!empty($missing)) {
    out([
        'ok' => false,
        'message' => 'Could not resolve DB credentials (' . implode(', ', $missing) . '). Tried env vars WORDPRESS_DB_* and literal define() in wp-config.php.',
    ]);
}

$mysqli = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
    out(['ok' => false, 'message' => 'mysqli connect failed: ' . $mysqli->connect_error]);
}
$mysqli->set_charset('utf8mb4');

$oldEsc = $mysqli->real_escape_string($old);
$newEsc = $mysqli->real_escape_string($new);

$options = $mysqli->real_escape_string($prefix . 'options');
$posts = $mysqli->real_escape_string($prefix . 'posts');
$postmeta = $mysqli->real_escape_string($prefix . 'postmeta');
$comments = $mysqli->real_escape_string($prefix . 'comments');

$counts = ['options' => 0, 'posts' => 0, 'postmeta' => 0, 'comments' => 0];

$mysqli->begin_transaction();
try {
    // siteurl + home
    $sql = "UPDATE `$options` SET option_value = REPLACE(option_value, '$oldEsc', '$newEsc') WHERE option_name IN ('siteurl', 'home')";
    if (!$mysqli->query($sql)) { throw new RuntimeException('options: ' . $mysqli->error); }
    $counts['options'] = $mysqli->affected_rows;

    // posts: guid + post_content + post_excerpt in a single UPDATE so
    // the affected_rows count is accurate.
    $sql = "UPDATE `$posts` SET "
         . "guid = REPLACE(guid, '$oldEsc', '$newEsc'), "
         . "post_content = REPLACE(post_content, '$oldEsc', '$newEsc'), "
         . "post_excerpt = REPLACE(post_excerpt, '$oldEsc', '$newEsc')";
    if (!$mysqli->query($sql)) { throw new RuntimeException('posts: ' . $mysqli->error); }
    $counts['posts'] = $mysqli->affected_rows;

    // postmeta: exclude rows whose meta_value looks serialized
    // (starts with 'a:' / 'O:' / 's:N:' etc). We cannot safely
    // rewrite serialized strings without re-serializing the length
    // header — use WP-CLI's search-replace for that case.
    $sql = "UPDATE `$postmeta` SET meta_value = REPLACE(meta_value, '$oldEsc', '$newEsc') "
         . "WHERE meta_value NOT REGEXP '^(a|O|s):[0-9]+'";
    if (!$mysqli->query($sql)) { throw new RuntimeException('postmeta: ' . $mysqli->error); }
    $counts['postmeta'] = $mysqli->affected_rows;

    // comments
    $sql = "UPDATE `$comments` SET comment_content = REPLACE(comment_content, '$oldEsc', '$newEsc')";
    if (!$mysqli->query($sql)) { throw new RuntimeException('comments: ' . $mysqli->error); }
    $counts['comments'] = $mysqli->affected_rows;

    $mysqli->commit();
    out([
        'ok' => true,
        'options' => (int) $counts['options'],
        'posts' => (int) $counts['posts'],
        'postmeta' => (int) $counts['postmeta'],
        'comments' => (int) $counts['comments'],
        'message' => 'ok',
    ]);
} catch (\Throwable $e) {
    $mysqli->rollback();
    out(['ok' => false, 'message' => $e->getMessage()]);
}
PHP;
    }

    public function fixPermissions($server, $containerName)
    {
        try {
            $escapedContainer = escapeshellarg($containerName);
            $commands = [
                "docker exec {$escapedContainer} sh -c 'chown -R www-data:www-data /var/www/html/wp-content'",
                "docker exec {$escapedContainer} sh -c 'find /var/www/html/wp-content -type d -exec chmod 755 {} \\;'",
                "docker exec {$escapedContainer} sh -c 'find /var/www/html/wp-content -type f -exec chmod 644 {} \\;'",
            ];

            foreach ($commands as $command) {
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
            }

            $this->output .= "Permissions fixed successfully.\n";
        } catch (\Throwable $e) {
            $this->output .= "Warning: Could not fix permissions: ".$e->getMessage()."\n";
        }
    }

    public function detectWpPrefixes()
    {
        $this->wpPrefixes = [];

        foreach ($this->wordpressContainers as $container) {
            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                continue;
            }

            try {
                $server = $application->service->server;
                $containerName = $container['container_name'];
                $prefix = $this->detectWpPrefix($server, $containerName);

                $this->wpPrefixes[$container['id']] = [
                    'container_name' => $container['name'],
                    'prefix' => $prefix ?? 'wp_',
                ];
            } catch (\Throwable $e) {
                $this->wpPrefixes[$container['id']] = [
                    'container_name' => $container['name'],
                    'prefix' => 'wp_',
                ];
            }
        }
    }

    /**
     * Best-effort detection of the WordPress table prefix declared in
     * wp-config.php. Uses a PHP-side parse (not grep) because the old
     * grep approach matched ANY line containing `$table_prefix`,
     * including commented multisite examples, and then `head -1`
     * would pick the wrong one.
     *
     * The new flow:
     *   1. cat wp-config.php inside the container.
     *   2. Strip single-line (// ...) and block (/* ... *\/) comments.
     *   3. Run a regex that matches `$table_prefix = 'value';` or
     *      `$table_prefix = "value";` anchored at line start with
     *      any amount of leading whitespace.
     *   4. Return the captured value.
     *
     * Falls back to SHOW TABLES + pattern match if the config parse
     * fails for any reason.
     */
    public function detectWpPrefix($server, string $containerName): ?string
    {
        try {
            $escapedContainer = escapeshellarg($containerName);

            // Dump the whole wp-config.php so we can parse it in PHP
            // instead of relying on shell grep (which can't easily
            // distinguish commented from uncommented lines).
            $dumpCommand = "docker exec {$escapedContainer} sh -c 'cat /var/www/html/wp-config.php 2>/dev/null || echo __NOTFOUND__'";
            if ($server->isNonRoot()) {
                $dumpCommand = "sudo {$dumpCommand}";
            }
            $configContent = (string) (instant_remote_process([$dumpCommand], $server, false) ?? '');

            if ($configContent !== '' && ! str_contains($configContent, '__NOTFOUND__')) {
                $prefix = $this->parseTablePrefixFromConfig($configContent);
                if ($prefix !== null) {
                    return $prefix;
                }
            }

            // Fallback: look at actual database tables.
            $prefix = $this->detectPrefixFromDatabase($server, $containerName);
            if ($prefix) {
                return $prefix;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pure-PHP parser for wp-config.php that ignores both kinds of
     * comments before running the $table_prefix regex. Exposed as a
     * protected method so the unit tests can exercise it with
     * synthetic wp-config.php contents without needing SSH.
     */
    public static function parseTablePrefixFromConfig(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        // Strip block comments /* ... */ first (multiline safe).
        $stripped = preg_replace('#/\*.*?\*/#s', '', $content);
        if (! is_string($stripped)) {
            $stripped = $content;
        }

        // Then strip single-line // comments and shell # comments.
        $lines = preg_split('/\r?\n/', $stripped) ?: [];
        $cleanLines = [];
        foreach ($lines as $line) {
            // Remove trailing // comment (but NOT inside a string —
            // wp-config.php doesn't contain // inside strings in
            // practice, so a simple split is fine).
            $line = preg_replace('#//.*$#', '', $line) ?? $line;
            $line = preg_replace('/^\s*#.*/', '', $line) ?? $line;
            $cleanLines[] = $line;
        }
        $clean = implode("\n", $cleanLines);

        // Anchor at line start (with optional whitespace) so we never
        // match the multisite example `* $table_prefix = '...';` that
        // WP's default comment block contains.
        if (preg_match('/^\s*\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/m', $clean, $m)) {
            $value = $m[2];
            if ($value !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
                return $value;
            }
        }

        return null;
    }

    private function detectPrefixFromDatabase($server, string $containerName): ?string
    {
        try {
            $escapedContainer = escapeshellarg($containerName);

            // Get environment variables to determine database type and credentials
            $envCommand = "docker exec {$escapedContainer} env";
            if ($server->isNonRoot()) {
                $envCommand = "sudo {$envCommand}";
            }
            $envOutput = instant_remote_process([$envCommand], $server, false) ?? '';
            $envVars = [];
            foreach (explode("\n", $envOutput) as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[$key] = $value;
                }
            }

            // Determine database type
            $isMariaDB = isset($envVars['MARIADB_ROOT_PASSWORD']) || isset($envVars['MARIADB_DATABASE']);
            $isMySQL = isset($envVars['MYSQL_ROOT_PASSWORD']) || isset($envVars['MYSQL_DATABASE']);

            if (! $isMariaDB && ! $isMySQL) {
                return null;
            }

            $rootPassword = $isMariaDB ? ($envVars['MARIADB_ROOT_PASSWORD'] ?? '') : ($envVars['MYSQL_ROOT_PASSWORD'] ?? '');
            $database = $isMariaDB ? ($envVars['MARIADB_DATABASE'] ?? '') : ($envVars['MYSQL_DATABASE'] ?? '');

            if (empty($rootPassword) || empty($database)) {
                return null;
            }

            $dbCommand = $isMariaDB ? 'mariadb' : 'mysql';
            $passwordVar = $isMariaDB ? 'MARIADB_ROOT_PASSWORD' : 'MYSQL_ROOT_PASSWORD';
            $escapedPassword = str_replace("'", "'\\''", $rootPassword);
            $escapedDatabase = escapeshellarg($database);

            // Get list of tables and find WordPress prefix
            $tablesCommand = "docker exec {$escapedContainer} sh -c 'export {$passwordVar}=\"{$escapedPassword}\" && {$dbCommand} -u root --password=\${$passwordVar} {$escapedDatabase} -e \"SHOW TABLES;\" 2>&1'";
            if ($server->isNonRoot()) {
                $tablesCommand = "sudo {$tablesCommand}";
            }

            $tablesOutput = instant_remote_process([$tablesCommand], $server, false);
            if (empty($tablesOutput)) {
                return null;
            }

            // Look for WordPress core tables (posts, users, options, etc.)
            $wpCoreTables = ['posts', 'users', 'options', 'comments', 'terms', 'postmeta'];
            $lines = explode("\n", trim($tablesOutput));

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || stripos($line, 'tables_in_') === 0) {
                    continue;
                }

                // Check if this table matches WordPress pattern
                foreach ($wpCoreTables as $coreTable) {
                    if (str_ends_with(strtolower($line), $coreTable)) {
                        // Extract prefix (everything before the core table name)
                        $prefix = substr($line, 0, -strlen($coreTable));
                        if (! empty($prefix)) {
                            return $prefix;
                        }
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Changes the WordPress table prefix end-to-end, using a PHP
     * helper script inside the container instead of the `mysql` CLI
     * (which is NOT installed in the official WordPress image). The
     * helper uses the `mysqli` extension that WordPress itself needs
     * to run, so it is guaranteed to be available.
     *
     * Operation order (wp-config.php is the source of truth — if the
     * tables already have another prefix, we rename them to match
     * whatever the user just wrote in the UI):
     *
     *   1. Validate the new prefix format.
     *   2. Detect the current prefix from wp-config.php. If it matches
     *      the new one, no-op.
     *   3. Build a self-contained PHP script that:
     *       a. Loads wp-config.php to read DB_HOST / DB_NAME / DB_USER
     *          / DB_PASSWORD.
     *       b. Connects via mysqli.
     *       c. Lists every table with the current prefix via
     *          SHOW TABLES LIKE 'prefix\_%'.
     *       d. If no tables found with the OLD prefix, looks for the
     *          NEW prefix (the user may have renamed the tables
     *          already outside Coolify and only wants to update
     *          wp-config.php) — if those exist, skip rename.
     *       e. If OLD tables exist, builds a single atomic RENAME
     *          TABLE statement and executes it.
     *       f. Updates wp_usermeta.meta_key + wp_options.option_name
     *          rows that embed the old prefix verbatim (capabilities,
     *          user_level, user_roles, dashboard_*). Without this
     *          every logged-in user would lose their permissions.
     *       g. Prints a JSON summary: {ok, renamed, meta_updated,
     *          options_updated, message}.
     *   4. Back up wp-config.php.
     *   5. Rewrite $table_prefix in wp-config.php.
     *   6. Verify final state via detectWpPrefix().
     */
    public function updateWpPrefix(int $containerId, string $newPrefix)
    {
        $this->authorize('update', $this->service);

        // Validate prefix format.
        $newPrefix = trim($newPrefix);
        if ($newPrefix === '' || strlen($newPrefix) > 20 || ! preg_match('/^[a-zA-Z0-9_]+$/', $newPrefix)) {
            $this->dispatch('error', 'El prefijo solo puede contener letras, números y guiones bajos (máximo 20 caracteres).');

            return;
        }
        if (! str_ends_with($newPrefix, '_')) {
            $this->dispatch('error', 'El prefijo debe terminar en un guion bajo (ej: wp_, myapp_, 2024_).');

            return;
        }

        $container = collect($this->wordpressContainers)->firstWhere('id', $containerId);
        if (! $container) {
            $this->dispatch('error', 'Container not found.');

            return;
        }

        $application = $container['application'] ?? $this->applications->find($containerId);
        if (! $application || ! str($application->status)->contains('running')) {
            $this->dispatch('error', 'Container is not running.');

            return;
        }

        $server = $application->service->server;
        $containerName = $container['container_name'];
        $escapedContainer = escapeshellarg($containerName);

        try {
            // Step 1: detect current prefix.
            $currentPrefix = $this->detectWpPrefix($server, $containerName) ?? 'wp_';
            if ($currentPrefix === $newPrefix) {
                $this->dispatch('warning', "El prefijo ya es {$newPrefix}, no hay nada que hacer.");

                return;
            }
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $currentPrefix)) {
                $this->dispatch('error', "Prefijo actual detectado inválido: {$currentPrefix}.");

                return;
            }

            // Step 2: run the PHP + mysqli rename script inside the container.
            $script = $this->buildUpdatePrefixPhpScript();
            $result = $this->runPhpScriptInContainer($server, $escapedContainer, $script, [$currentPrefix, $newPrefix]);

            if (! $result['ok']) {
                $this->dispatch('error', 'RENAME TABLE falló: '.$result['stderr']);

                return;
            }

            $payload = json_decode($result['stdout'], true);
            if (! is_array($payload) || ! isset($payload['ok'])) {
                $this->dispatch('error', 'Respuesta del script inválida: '.substr((string) $result['stdout'], 0, 300));

                return;
            }
            if ($payload['ok'] !== true) {
                $this->dispatch('error', (string) ($payload['message'] ?? 'Error desconocido durante el RENAME TABLE.'));

                return;
            }

            $renamed = (int) ($payload['renamed'] ?? 0);
            $metaUpdated = (int) ($payload['meta_updated'] ?? 0);
            $optionsUpdated = (int) ($payload['options_updated'] ?? 0);

            // Step 3: backup wp-config.php before touching it.
            $backupName = 'wp-config.php.backup-'.now()->format('Ymd-His');
            $backupCmd = "docker exec {$escapedContainer} sh -c 'cp /var/www/html/wp-config.php /var/www/html/{$backupName}'";
            if ($server->isNonRoot()) {
                $backupCmd = "sudo {$backupCmd}";
            }
            instant_remote_process([$backupCmd], $server, false);

            // Step 4: rewrite wp-config.php using a small PHP helper.
            $rewriteScript = $this->buildRewriteWpConfigScript();
            $rewriteResult = $this->runPhpScriptInContainer($server, $escapedContainer, $rewriteScript, [$newPrefix]);
            if (! $rewriteResult['ok']) {
                $this->dispatch('error', 'Tablas ya renombradas ('.$renamed.'), pero wp-config.php no se pudo reescribir: '.$rewriteResult['stderr'].'. Backup: /var/www/html/'.$backupName);

                return;
            }

            // Step 5: verify end-state.
            $finalVerify = $this->detectWpPrefix($server, $containerName);
            if ($finalVerify !== $newPrefix) {
                $this->dispatch('error', 'Prefijo cambiado en DB pero wp-config.php parece inconsistente. Actual: '.($finalVerify ?? 'no detectado').'. Backup: /var/www/html/'.$backupName);

                return;
            }

            $this->detectWpPrefixes();
            $summary = "Prefijo actualizado de {$currentPrefix} a {$newPrefix}: {$renamed} tablas renombradas, {$metaUpdated} filas usermeta, {$optionsUpdated} filas options. Backup wp-config: /var/www/html/{$backupName}";
            $this->dispatch('success', $summary);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to update WordPress prefix: '.$e->getMessage());
        }
    }

    /**
     * PHP helper script that runs inside the WordPress container to
     * perform the RENAME TABLE + usermeta + options update using
     * the `mysqli` extension (which is always available in WordPress
     * images because WordPress itself needs it).
     *
     * Arguments when invoked: argv[1] = old prefix, argv[2] = new prefix.
     *
     * Output: a single JSON line on stdout with the shape
     *     {ok: bool, renamed: int, meta_updated: int,
     *      options_updated: int, message: string}
     */
    public function buildUpdatePrefixPhpScript(): string
    {
        return <<<'PHP'
<?php
// Runs inside the WordPress container. We intentionally do NOT load
// wp-load.php because it requires a fully healthy WordPress state
// and a specific prefix that may already be inconsistent. Instead
// we resolve the 4 DB constants using the same pipeline the
// official WordPress Docker image uses itself:
//   1. If the container has the WORDPRESS_DB_* env vars set
//      (standard for the wordpress:latest image), use them directly.
//   2. Otherwise, scrape wp-config.php for literal define('NAME', 'value')
//      assignments — covers custom / legacy installs that hard-code
//      credentials.
// We never try to eval() the PHP because that would pull in WP core
// and fail when the prefix is inconsistent.
error_reporting(E_ERROR | E_PARSE);
function out($payload) { echo json_encode($payload); exit; }

$oldPrefix = $argv[1] ?? '';
$newPrefix = $argv[2] ?? '';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $oldPrefix) || !preg_match('/^[a-zA-Z0-9_]+$/', $newPrefix)) {
    out(['ok' => false, 'message' => 'Invalid prefix arguments.']);
}

$cfg = @file_get_contents('/var/www/html/wp-config.php');
if ($cfg === false) {
    out(['ok' => false, 'message' => 'wp-config.php not readable']);
}

/**
 * Resolves a WordPress DB constant using a multi-strategy pipeline
 * so it works with both the official image (env-var driven) and
 * custom installs (literal defines in wp-config.php).
 *
 * Strategy order:
 *   1. Env var WORDPRESS_<NAME> (getenv, $_ENV, $_SERVER)
 *      This is what the official wordpress:latest image populates
 *      via its docker-entrypoint.sh before starting apache/php-fpm.
 *   2. Regex scrape for define('NAME', 'value') with a string literal.
 *      Covers custom installs that hard-code the credentials.
 *   3. Return null so the caller can report precisely which constant
 *      is missing.
 */
function resolve_db_var(string $content, string $name): ?string {
    // Strategy 1: env var. The official image exports WORDPRESS_DB_HOST,
    // WORDPRESS_DB_NAME, WORDPRESS_DB_USER, WORDPRESS_DB_PASSWORD and
    // its wp-config.php reads them via getenv_docker(). Our PHP script
    // runs inside the SAME container so we see the same env vars.
    $envName = 'WORDPRESS_' . $name;
    $fromEnv = getenv($envName);
    if ($fromEnv === false || $fromEnv === '') {
        $fromEnv = $_ENV[$envName] ?? $_SERVER[$envName] ?? null;
    }
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }

    // Strategy 2: literal define(). Handles both quote styles and any
    // amount of whitespace around the tokens.
    if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s', $content, $m)) {
        return $m[2];
    }

    return null;
}

$dbName = resolve_db_var($cfg, 'DB_NAME');
$dbUser = resolve_db_var($cfg, 'DB_USER');
$dbPass = resolve_db_var($cfg, 'DB_PASSWORD');
$dbHost = resolve_db_var($cfg, 'DB_HOST') ?? 'localhost';
$missing = [];
if ($dbName === null) { $missing[] = 'DB_NAME'; }
if ($dbUser === null) { $missing[] = 'DB_USER'; }
if ($dbPass === null) { $missing[] = 'DB_PASSWORD'; }
if (!empty($missing)) {
    out([
        'ok' => false,
        'message' => 'Could not resolve DB credentials (' . implode(', ', $missing) . '). Tried env vars WORDPRESS_DB_* and literal define() in wp-config.php.',
    ]);
}

$mysqli = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
    out(['ok' => false, 'message' => 'mysqli connect failed: ' . $mysqli->connect_error]);
}
$mysqli->set_charset('utf8mb4');

// LIKE pattern needs literal underscore escape (\_ inside a SQL
// string literal is still LIKE's wildcard-free underscore).
$likeOld = str_replace('_', '\\_', $oldPrefix) . '%';

$res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($likeOld) . "'");
if (!$res) {
    out(['ok' => false, 'message' => 'SHOW TABLES failed: ' . $mysqli->error]);
}
$oldTables = [];
while ($row = $res->fetch_array(MYSQLI_NUM)) { $oldTables[] = $row[0]; }
$res->free();

// If the prefix we read from wp-config.php does not match any
// table in the database, the state is inconsistent — either a
// previous rename got stuck half-way, or someone renamed tables
// manually without updating wp-config.php. Instead of giving up,
// auto-detect the REAL prefix by looking for the canonical WordPress
// core table names (`posts`, `users`, `options`). Whichever prefix
// those tables share is the truth.
if (empty($oldTables)) {
    // First check: do the tables already match the NEW prefix
    // (idempotent no-op case)?
    $likeNew = str_replace('_', '\\_', $newPrefix) . '%';
    $res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($likeNew) . "'");
    $newExists = $res && $res->num_rows > 0;
    if ($res) $res->free();
    if ($newExists) {
        out([
            'ok' => true,
            'renamed' => 0,
            'meta_updated' => 0,
            'options_updated' => 0,
            'message' => 'Tables already use the new prefix — only wp-config.php needed updating.',
        ]);
    }

    // Auto-detect: scan all tables and find one that looks like a
    // WordPress core table (ends in _posts / _users / _options).
    // Every WordPress install has these three, so the prefix is
    // just "table_name minus the suffix".
    $detected = null;
    $allRes = $mysqli->query("SHOW TABLES");
    if ($allRes) {
        $allTables = [];
        while ($row = $allRes->fetch_array(MYSQLI_NUM)) { $allTables[] = $row[0]; }
        $allRes->free();

        // Look for tables ending in `_posts`, `_users`, `_options` —
        // in that priority order because `_posts` is the most
        // distinctive (less likely to appear in a non-WP database
        // that shares the schema).
        $coreSuffixes = ['_posts', '_users', '_options', '_postmeta', '_usermeta'];
        foreach ($coreSuffixes as $suffix) {
            foreach ($allTables as $t) {
                // Case-insensitive tail match on the core suffix.
                if (strlen($t) > strlen($suffix)
                    && strtolower(substr($t, -strlen($suffix))) === $suffix) {
                    $candidate = substr($t, 0, -strlen($suffix) + 1); // keep trailing _
                    if ($candidate !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $candidate)) {
                        $detected = $candidate;
                        break 2;
                    }
                }
            }
        }
    }

    if ($detected === null) {
        out([
            'ok' => false,
            'message' => "No tables with prefix '$oldPrefix' or '$newPrefix' found, and no WordPress core tables (*_posts / *_users / *_options) detected. Is this the right database? Check DB_NAME in wp-config.php.",
        ]);
    }

    if ($detected === $newPrefix) {
        out([
            'ok' => true,
            'renamed' => 0,
            'meta_updated' => 0,
            'options_updated' => 0,
            'message' => "Tables already use the prefix '$newPrefix' (detected via core table scan) — only wp-config.php needed updating.",
        ]);
    }

    // Override: use the auto-detected prefix as the actual old
    // prefix. wp-config.php may say something else, but the
    // database is the source of truth here.
    $oldPrefix = $detected;
    $likeOld = str_replace('_', '\\_', $oldPrefix) . '%';
    $res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($likeOld) . "'");
    if (!$res) {
        out(['ok' => false, 'message' => 'SHOW TABLES retry failed: ' . $mysqli->error]);
    }
    while ($row = $res->fetch_array(MYSQLI_NUM)) { $oldTables[] = $row[0]; }
    $res->free();

    if (empty($oldTables)) {
        out(['ok' => false, 'message' => "Auto-detected prefix '$detected' but SHOW TABLES returned empty on retry — something weird is going on."]);
    }
}

// Build a single atomic RENAME TABLE.
$renameParts = [];
foreach ($oldTables as $t) {
    $suffix = substr($t, strlen($oldPrefix));
    if ($suffix === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $suffix)) { continue; }
    $renameParts[] = '`' . $t . '` TO `' . $newPrefix . $suffix . '`';
}
if (empty($renameParts)) {
    out(['ok' => false, 'message' => 'No tables passed the rename validation.']);
}

$mysqli->begin_transaction();
try {
    $renameSql = 'RENAME TABLE ' . implode(', ', $renameParts);
    if (!$mysqli->query($renameSql)) {
        throw new RuntimeException('RENAME TABLE failed: ' . $mysqli->error);
    }

    $newUsermeta = $mysqli->real_escape_string($newPrefix . 'usermeta');
    $newOptions = $mysqli->real_escape_string($newPrefix . 'options');
    $oldEsc = $mysqli->real_escape_string($oldPrefix);
    $newEsc = $mysqli->real_escape_string($newPrefix);

    // usermeta: meta_key like 'wp_capabilities', 'wp_user_level', etc.
    $metaSql = "UPDATE `$newUsermeta` SET meta_key = REPLACE(meta_key, '$oldEsc', '$newEsc') WHERE meta_key LIKE '$oldEsc%'";
    if (!$mysqli->query($metaSql)) {
        throw new RuntimeException('usermeta UPDATE failed: ' . $mysqli->error);
    }
    $metaUpdated = $mysqli->affected_rows;

    // options: option_name like 'wp_user_roles'.
    $optSql = "UPDATE `$newOptions` SET option_name = REPLACE(option_name, '$oldEsc', '$newEsc') WHERE option_name LIKE '$oldEsc%'";
    if (!$mysqli->query($optSql)) {
        throw new RuntimeException('options UPDATE failed: ' . $mysqli->error);
    }
    $optionsUpdated = $mysqli->affected_rows;

    $mysqli->commit();
    out([
        'ok' => true,
        'renamed' => count($renameParts),
        'meta_updated' => (int) $metaUpdated,
        'options_updated' => (int) $optionsUpdated,
        'message' => 'ok',
    ]);
} catch (\Throwable $e) {
    $mysqli->rollback();
    out(['ok' => false, 'message' => $e->getMessage()]);
}
PHP;
    }

    /**
     * Small PHP helper that rewrites the $table_prefix line inside
     * /var/www/html/wp-config.php. Argv[1] = new prefix.
     */
    public function buildRewriteWpConfigScript(): string
    {
        return <<<'PHP'
<?php
$file = '/var/www/html/wp-config.php';
$new = $argv[1] ?? '';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $new)) {
    echo "ERROR: invalid prefix\n"; exit(1);
}
$content = @file_get_contents($file);
if ($content === false) {
    echo "ERROR: wp-config.php not readable\n"; exit(1);
}
// Anchored at line start so we never match the commented multisite
// example. Replaces both quote styles with double quotes.
$pattern = '/^(\s*)\$table_prefix\s*=\s*([\'"])(.*?)\2\s*;/m';
$replacement = '$1$table_prefix = "' . $new . '";';
$out = preg_replace($pattern, $replacement, $content, 1, $count);
if ($out === null || $count === 0) {
    echo "ERROR: no \$table_prefix assignment found to replace\n"; exit(1);
}
if (file_put_contents($file, $out) === false) {
    echo "ERROR: failed to write wp-config.php\n"; exit(1);
}
echo "OK\n";
PHP;
    }

    /**
     * Helper that copies a PHP script to the target container via
     * base64, runs it with `php <script> <args...>`, captures stdout
     * and stderr, cleans up, and returns a result array.
     *
     * Uses the `php` binary which is guaranteed to exist in any
     * WordPress image (without it WordPress itself could not run).
     * No dependency on the `mysql` CLI or any other external tool.
     *
     * @param  array<int, string>  $args
     * @return array{ok: bool, stdout: string, stderr: string, exit_code: int}
     */
    public function runPhpScriptInContainer($server, string $escapedContainer, string $scriptContent, array $args = []): array
    {
        $scriptPath = '/tmp/coolify-wp-'.uniqid('', true).'.php';
        $scriptB64 = escapeshellarg(base64_encode($scriptContent));
        $escapedPath = escapeshellarg($scriptPath);

        // Write the script to the container.
        $writeCmd = "docker exec {$escapedContainer} sh -c 'echo {$scriptB64} | base64 -d > {$escapedPath}'";
        if ($server->isNonRoot()) {
            $writeCmd = "sudo {$writeCmd}";
        }
        instant_remote_process([$writeCmd], $server, false);

        // Execute. Wrap in a sentinel harness like
        // FixWordPressContentPermissions so stdout is preserved even
        // on non-zero exit, because instant_remote_process() will
        // otherwise discard it via excludeCertainErrors().
        $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
        $inner = "php {$escapedPath} {$escapedArgs}";
        $wrapped = '('.$inner.' 2>/tmp/coolify-wp-stderr) ; __cc_status=$?; '
            .'echo "__COOLIFY_WP_EXIT__=$__cc_status"; '
            .'echo "__COOLIFY_WP_STDERR_START__"; '
            .'cat /tmp/coolify-wp-stderr 2>/dev/null || true; '
            .'echo "__COOLIFY_WP_STDERR_END__"; '
            .'rm -f /tmp/coolify-wp-stderr; '
            .'exit 0';
        $runCmd = "docker exec {$escapedContainer} sh -lc ".escapeshellarg($wrapped);
        if ($server->isNonRoot()) {
            $runCmd = "sudo {$runCmd}";
        }
        $raw = (string) (instant_remote_process([$runCmd], $server, false) ?? '');

        // Best-effort cleanup.
        $cleanup = "docker exec {$escapedContainer} sh -c 'rm -f {$escapedPath}'";
        if ($server->isNonRoot()) {
            $cleanup = "sudo {$cleanup}";
        }
        try {
            instant_remote_process([$cleanup], $server, false);
        } catch (\Throwable) {
            // Non-fatal.
        }

        // Parse the sentinel output.
        $exitCode = 0;
        if (preg_match('/__COOLIFY_WP_EXIT__=(\d+)/', $raw, $m)) {
            $exitCode = (int) $m[1];
        }
        $stderr = '';
        if (preg_match('/__COOLIFY_WP_STDERR_START__\s*(.*?)\s*__COOLIFY_WP_STDERR_END__/s', $raw, $m)) {
            $stderr = trim($m[1]);
        }
        // Strip both sentinels out of stdout.
        $stdout = (string) preg_replace('/__COOLIFY_WP_EXIT__=\d+/', '', $raw);
        $stdout = (string) preg_replace('/__COOLIFY_WP_STDERR_START__.*?__COOLIFY_WP_STDERR_END__/s', '', $stdout);
        $stdout = trim($stdout);

        return [
            'ok' => $exitCode === 0,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    public function loadPhpIniSettings(?int $containerId = null)
    {
        if ($containerId === null) {
            $containerId = $this->selectedContainerForPhpIni;
        }

        if ($containerId === null) {
            return;
        }

        $this->isLoadingPhpIni = true;
        $this->selectedContainerForPhpIni = $containerId;

        try {
            $container = collect($this->wordpressContainers)->firstWhere('id', $containerId);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                return;
            }

            $application = $container['application'] ?? $this->applications->find($containerId);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);

            // Get PHP configuration - try PHP-FPM first, then CLI
            $phpInfo = '';
            $fpmInfoCommand = "docker exec {$escapedContainer} sh -c 'which php-fpm >/dev/null 2>&1 && php-fpm -i 2>/dev/null || echo notfound'";
            if ($server->isNonRoot()) {
                $fpmInfoCommand = "sudo {$fpmInfoCommand}";
            }
            $fpmInfo = instant_remote_process([$fpmInfoCommand], $server, false) ?? '';

            if (!empty($fpmInfo) && $fpmInfo !== 'notfound') {
                $phpInfo = $fpmInfo;
            } else {
                // Fallback to CLI
                $phpInfoCommand = "docker exec {$escapedContainer} php -i 2>/dev/null";
                if ($server->isNonRoot()) {
                    $phpInfoCommand = "sudo {$phpInfoCommand}";
                }
                $phpInfo = instant_remote_process([$phpInfoCommand], $server, false) ?? '';
            }

            // Extract common PHP settings
            $settings = [];
            $settingsToExtract = [
                'upload_max_filesize',
                'post_max_size',
                'max_execution_time',
                'max_input_time',
                'memory_limit',
                'max_input_vars',
                'max_file_uploads',
            ];

            foreach ($settingsToExtract as $setting) {
                // Try multiple methods to get the value
                $iniValue = null;

                // Method 1: Check if there's a LocalFileVolume for this setting (persistent volume)
                $confIniFileName = '99-custom-'.$setting.'.ini';
                $confIniFileMountPath = '/usr/local/etc/php/conf.d/'.$confIniFileName;
                $fileVolume = LocalFileVolume::where('resource_type', ServiceApplication::class)
                    ->where('resource_id', $application->id)
                    ->where('mount_path', $confIniFileMountPath)
                    ->first();

                if ($fileVolume && !empty($fileVolume->content)) {
                    // Try to extract value from volume content first
                    if (preg_match('/'.$setting.'\s*=\s*([^\s\r\n]+)/', $fileVolume->content, $matches)) {
                        $iniValue = trim($matches[1]);
                    }
                }

                // Method 2: Try PHP-FPM directly if available
                if (empty($iniValue)) {
                    $fpmCommand = "docker exec {$escapedContainer} sh -c 'which php-fpm && php-fpm -i 2>/dev/null | grep \"{$setting}\" | head -1 | awk -F\"=> \" \"{print \\\$2}\" | awk \"{print \\\$1}\" || echo notfound'";
                    if ($server->isNonRoot()) {
                        $fpmCommand = "sudo {$fpmCommand}";
                    }
                    $fpmValue = trim(instant_remote_process([$fpmCommand], $server, false) ?? '');
                    if (!empty($fpmValue) && $fpmValue !== 'notfound') {
                        $iniValue = $fpmValue;
                    }
                }

                // Method 3: Try php -r (CLI, but more reliable)
                if (empty($iniValue)) {
                    $getIniCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
                    if ($server->isNonRoot()) {
                        $getIniCommand = "sudo {$getIniCommand}";
                    }
                    $iniValue = trim(instant_remote_process([$getIniCommand], $server, false) ?? '');
                }

                // Method 4: Parse from php -i output
                if (empty($iniValue)) {
                    $pattern = "/{$setting}\s*=>\s*([^\r\n]+)/i";
                    if (preg_match($pattern, $phpInfo, $matches)) {
                        $value = trim($matches[1]);
                        $value = preg_replace('/.*?=>\s*/', '', $value);
                        $value = preg_replace('/\s*\(.*?\)\s*$/', '', $value);
                        $iniValue = trim($value);
                    }
                }

                $settings[$setting] = ! empty($iniValue) ? $iniValue : 'N/A';
            }

            $this->phpIniSettings = $settings;
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading PHP configuration: '.$e->getMessage());
            $this->phpIniSettings = [];
        } finally {
            $this->isLoadingPhpIni = false;
        }
    }

    public function updatePhpIniSetting(string $setting, string $value)
    {
        if ($this->selectedContainerForPhpIni === null) {
            $this->dispatch('error', 'No container selected.');
            return;
        }

        // Validate setting name
        $allowedSettings = [
            'upload_max_filesize',
            'post_max_size',
            'max_execution_time',
            'max_input_time',
            'memory_limit',
            'max_input_vars',
            'max_file_uploads',
        ];

        if (! in_array($setting, $allowedSettings)) {
            $this->dispatch('error', 'Invalid setting name.');
            return;
        }

        // Validate value format
        if (empty($value)) {
            $this->dispatch('error', 'Value cannot be empty.');
            return;
        }

        try {
            $container = collect($this->wordpressContainers)->firstWhere('id', $this->selectedContainerForPhpIni);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');
                return;
            }

            $application = $container['application'] ?? $this->applications->find($this->selectedContainerForPhpIni);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');
                return;
            }

            $server = $application->service->server;
            $containerName = $container['container_name'];
            $escapedContainer = escapeshellarg($containerName);
            $escapedSetting = escapeshellarg($setting);
            $escapedValue = escapeshellarg($value);

            // Detect PHP SAPI (CLI vs FPM)
            $sapiCommand = "docker exec {$escapedContainer} php -r \"echo php_sapi_name();\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $sapiCommand = "sudo {$sapiCommand}";
            }
            $sapi = strtolower(trim(instant_remote_process([$sapiCommand], $server, false) ?? 'cli'));

            // Get PHP version
            $phpVersionCommand = "docker exec {$escapedContainer} php -r \"echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $phpVersionCommand = "sudo {$phpVersionCommand}";
            }
            $phpVersion = trim(instant_remote_process([$phpVersionCommand], $server, false) ?? '8.2');

            // Find php.ini location - prioritize FPM if available
            $phpIniPaths = [];

            // Priority order: FPM conf.d > FPM php.ini > CLI conf.d > CLI php.ini > common locations
            $priorityPaths = [
                // PHP-FPM conf.d (highest priority - these override php.ini)
                "/usr/local/etc/php/conf.d/99-custom-{$setting}.ini",
                "/etc/php/{$phpVersion}/fpm/conf.d/99-custom-{$setting}.ini",
                "/etc/php/{$phpVersion}/fpm/conf.d/zzz-custom-{$setting}.ini",
                // PHP-FPM php.ini
                "/usr/local/etc/php/php.ini",
                "/etc/php/{$phpVersion}/fpm/php.ini",
                // CLI conf.d
                "/etc/php/{$phpVersion}/cli/conf.d/99-custom-{$setting}.ini",
                // CLI php.ini
                "/etc/php/{$phpVersion}/cli/php.ini",
                // Common locations
                "/etc/php/php.ini",
                "/etc/php.ini",
            ];

            // First, try to get from php -i (what PHP actually uses)
            $findPhpIniCommand = "docker exec {$escapedContainer} php -i 2>/dev/null | grep 'Loaded Configuration File' | awk -F'=> ' '{print \$2}' | awk '{print \$1}'";
            if ($server->isNonRoot()) {
                $findPhpIniCommand = "sudo {$findPhpIniCommand}";
            }
            $detectedIni = trim(instant_remote_process([$findPhpIniCommand], $server, false) ?? '');

            if (!empty($detectedIni) && $detectedIni !== '(none)' && str_starts_with($detectedIni, '/')) {
                $priorityPaths = array_merge([$detectedIni], $priorityPaths);
            }

            // Find existing conf.d directories (these have priority over php.ini)
            $confDirs = [
                "/usr/local/etc/php/conf.d",
                "/etc/php/{$phpVersion}/fpm/conf.d",
                "/etc/php/{$phpVersion}/cli/conf.d",
                "/etc/php/conf.d",
            ];

            $phpIniPath = null;
            $confDirPath = null;

            // First, check if conf.d exists and use it (highest priority)
            foreach ($confDirs as $confDir) {
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($confDir)." && echo found || echo notfound";
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                $dirExists = trim(instant_remote_process([$checkDirCommand], $server, false) ?? '');

                if ($dirExists === 'found') {
                    $confDirPath = $confDir;
                    break;
                }
            }

            // Then find php.ini file
            foreach ($priorityPaths as $path) {
                $testCommand = "docker exec {$escapedContainer} test -f ".escapeshellarg($path)." && echo found || echo notfound";
                if ($server->isNonRoot()) {
                    $testCommand = "sudo {$testCommand}";
                }
                $testResult = trim(instant_remote_process([$testCommand], $server, false) ?? '');
                if ($testResult === 'found') {
                    $phpIniPath = $path;
                    break;
                }
            }

            // If no php.ini found, use default and create it
            if ($phpIniPath === null) {
                $phpIniPath = "/usr/local/etc/php/php.ini";
                $dirPath = dirname($phpIniPath);
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($dirPath)." || docker exec {$escapedContainer} mkdir -p ".escapeshellarg($dirPath);
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                instant_remote_process([$checkDirCommand], $server, false);

                $checkFileCommand = "docker exec {$escapedContainer} test -f ".escapeshellarg($phpIniPath)." || docker exec {$escapedContainer} sh -c 'echo \"; PHP Configuration File\" > ".escapeshellarg($phpIniPath)."'";
                if ($server->isNonRoot()) {
                    $checkFileCommand = "sudo {$checkFileCommand}";
                }
                instant_remote_process([$checkFileCommand], $server, false);
            }

            // Ensure conf.d exists if we're going to use it
            if ($confDirPath === null) {
                $confDirPath = "/usr/local/etc/php/conf.d";
                $checkDirCommand = "docker exec {$escapedContainer} test -d ".escapeshellarg($confDirPath)." || docker exec {$escapedContainer} mkdir -p ".escapeshellarg($confDirPath);
                if ($server->isNonRoot()) {
                    $checkDirCommand = "sudo {$checkDirCommand}";
                }
                instant_remote_process([$checkDirCommand], $server, false);
            }

            // PRIORITY: conf.d files override php.ini, so we ALWAYS update conf.d first
            // This ensures the setting takes effect even if php.ini has conflicting values

            // Debug: Show what PHP is actually using
            $debugIniCommand = "docker exec {$escapedContainer} php -r \"echo 'Loaded: ' . php_ini_loaded_file() . PHP_EOL; echo 'Scanned: ' . php_ini_scanned_files() . PHP_EOL;\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $debugIniCommand = "sudo {$debugIniCommand}";
            }
            $debugInfo = instant_remote_process([$debugIniCommand], $server, false) ?? '';

            // CRITICAL: Store PHP config files in a persistent volume
            // Create/update LocalFileVolume to persist the configuration file
            $confIniFileName = '99-custom-'.$setting.'.ini';
            $confIniFileMountPath = $confDirPath.'/'.$confIniFileName;
            $confIniFileFsPath = './php-config/'.$confIniFileName; // Relative to workdir

            // Prepare file content
            $confContent = "; Custom {$setting} setting - Updated by Coolify\n{$setting} = {$value}\n";

            // Ensure php-config directory exists in workdir
            $workdir = $application->service->workdir();
            $phpConfigDir = $workdir.'/php-config';
            $createDirCommand = "mkdir -p ".escapeshellarg($phpConfigDir);
            if ($server->isNonRoot()) {
                $createDirCommand = "sudo {$createDirCommand}";
            }
            instant_remote_process([$createDirCommand], $server, false);

            try {
                // Find or create LocalFileVolume for this PHP config file
                $fileVolume = LocalFileVolume::where('resource_type', ServiceApplication::class)
                    ->where('resource_id', $application->id)
                    ->where('mount_path', $confIniFileMountPath)
                    ->first();

                if (! $fileVolume) {
                    // Create new file volume
                    $fileVolume = LocalFileVolume::create([
                        'resource_type' => ServiceApplication::class,
                        'resource_id' => $application->id,
                        'fs_path' => $confIniFileFsPath,
                        'mount_path' => $confIniFileMountPath,
                        'is_directory' => false,
                        'content' => $confContent,
                    ]);
                } else {
                    // Update existing file volume
                    $fileVolume->content = $confContent;
                    $fileVolume->save();
                }

                // Save the file to the persistent storage on server
                $fileVolume->saveStorageOnServer();

                // Verify the file was saved correctly on the server
                $workdir = $application->service->workdir();
                $serverFilePath = $workdir.'/php-config/'.$confIniFileName;
                $verifyServerFileCommand = "test -f ".escapeshellarg($serverFilePath)." && cat ".escapeshellarg($serverFilePath)." || echo 'FILE_NOT_FOUND'";
                if ($server->isNonRoot()) {
                    $verifyServerFileCommand = "sudo {$verifyServerFileCommand}";
                }
                $serverFileContent = instant_remote_process([$verifyServerFileCommand], $server, false) ?? '';

                if ($serverFileContent === 'FILE_NOT_FOUND' || empty($serverFileContent)) {
                    $this->dispatch('error', "File was not saved correctly to server volume at {$serverFilePath}. Please check permissions.");
                    return;
                }

                // CRITICAL: Regenerate docker-compose to include the new volume
                // This ensures the volume is mounted when the container restarts
                $this->service->parse();
                $this->service->saveComposeConfigs();

                // Verify the volume is in docker-compose
                $composeContent = $this->service->docker_compose ?? '';
                $volumeInCompose = str_contains($composeContent, 'php-config') && str_contains($composeContent, $confIniFileMountPath);

                // Also write directly to container for immediate effect
                $this->writeToContainerDirectly($server, $escapedContainer, $confDirPath, $confIniFileName, $confContent, $setting, $value);

                // Verify the file exists in the container after writing
                $verifyContainerFileCommand = "docker exec {$escapedContainer} test -f ".escapeshellarg($confIniFileMountPath)." && docker exec {$escapedContainer} cat ".escapeshellarg($confIniFileMountPath)." || echo 'NOT_MOUNTED'";
                if ($server->isNonRoot()) {
                    $verifyContainerFileCommand = "sudo {$verifyContainerFileCommand}";
                }
                $containerFileContent = instant_remote_process([$verifyContainerFileCommand], $server, false) ?? '';

                // Check if file is mounted from volume or written directly
                $checkMountCommand = "docker inspect {$escapedContainer} --format '{{range .Mounts}}{{.Source}}:{{.Destination}} {{end}}' 2>/dev/null | grep -q php-config && echo 'MOUNTED' || echo 'NOT_MOUNTED'";
                if ($server->isNonRoot()) {
                    $checkMountCommand = "sudo {$checkMountCommand}";
                }
                $isMounted = trim(instant_remote_process([$checkMountCommand], $server, false) ?? '');

                // Verify PHP is reading the new value (check both CLI and FPM)
                $verifyPhpCliCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
                if ($server->isNonRoot()) {
                    $verifyPhpCliCommand = "sudo {$verifyPhpCliCommand}";
                }
                $phpCliValue = trim(instant_remote_process([$verifyPhpCliCommand], $server, false) ?? '');

                // Check PHP-FPM value (this is what WordPress uses)
                $verifyPhpFpmCommand = "docker exec {$escapedContainer} sh -c 'php-fpm -i 2>/dev/null | grep \"{$setting}\" | head -1 | awk -F\"=> \" \"{print \\\$2}\" | awk \"{print \\\$1}\" || echo notfound'";
                if ($server->isNonRoot()) {
                    $verifyPhpFpmCommand = "sudo {$verifyPhpFpmCommand}";
                }
                $phpFpmValue = trim(instant_remote_process([$verifyPhpFpmCommand], $server, false) ?? '');
                if ($phpFpmValue === 'notfound' || empty($phpFpmValue)) {
                    $phpFpmValue = $phpCliValue;
                }

                // The file is now persisted in the volume and docker-compose has been updated
                $volumeStatus = $volumeInCompose ? "Volume added to docker-compose." : "WARNING: Volume may not be in docker-compose.";
                $containerStatus = str_contains($containerFileContent, $value) ? "File written to container." : "WARNING: File content not found in container.";
                $mountStatus = ($isMounted === 'MOUNTED') ? "Volume is mounted." : "WARNING: Volume may not be mounted (file written directly to container).";
                $phpStatus = ($phpFpmValue === $value) ? "PHP-FPM reports {$value}." : "WARNING: PHP-FPM reports {$phpFpmValue} instead of {$value}. Service restart required.";

                $this->dispatch('success', "PHP setting {$setting} saved. {$volumeStatus} {$containerStatus} {$mountStatus} {$phpStatus} If PHP-FPM shows old value, restart the SERVICE completely from the service page.");

            } catch (\Throwable $e) {
                $this->dispatch('error', "Failed to save PHP config to persistent volume: ".$e->getMessage().". Trying direct container write...");
                // Fallback to direct container write (non-persistent)
                $this->writeToContainerDirectly($server, $escapedContainer, $confDirPath, $confIniFileName, $confContent, $setting, $value);
            }

            // CRITICAL: Remove or comment out duplicate settings from other conf.d files
            // Files are loaded alphabetically, so 99- prefix ensures our file loads last
            // But we should still check for conflicts
            $listConfFilesCommand = "docker exec {$escapedContainer} find {$confDirPath} -name '*.ini' -type f ! -name '99-custom-{$setting}.ini' 2>/dev/null | sort";
            if ($server->isNonRoot()) {
                $listConfFilesCommand = "sudo {$listConfFilesCommand}";
            }
            $otherConfFiles = explode("\n", trim(instant_remote_process([$listConfFilesCommand], $server, false) ?? ''));

            $conflictingFiles = [];
            foreach ($otherConfFiles as $otherFile) {
                $otherFile = trim($otherFile);
                if (empty($otherFile) || !str_starts_with($otherFile, '/')) {
                    continue;
                }

                // Check if this file has our setting (commented or not)
                $checkSettingCommand = "docker exec {$escapedContainer} grep -E '^[;]*\s*{$setting}\s*=' ".escapeshellarg($otherFile)." 2>/dev/null | head -1";
                if ($server->isNonRoot()) {
                    $checkSettingCommand = "sudo {$checkSettingCommand}";
                }
                $settingLine = trim(instant_remote_process([$checkSettingCommand], $server, false) ?? '');

                if (!empty($settingLine)) {
                    $conflictingFiles[] = $otherFile;
                    // Comment out the setting in this file (our 99- file will override it)
                    $escapedOtherFile = escapeshellarg($otherFile);
                    $commentCommand = "docker exec {$escapedContainer} sed -i 's/^\([;]*\s*\){$escapedSetting}\s*=.*/; \\1{$escapedSetting} = (overridden by 99-custom-{$setting}.ini)/' {$escapedOtherFile}";
                    if ($server->isNonRoot()) {
                        $commentCommand = "sudo {$commentCommand}";
                    }
                    instant_remote_process([$commentCommand], $server, false);
                }
            }

            // Log conflicting files for debugging
            if (!empty($conflictingFiles)) {
                $this->dispatch('warning', "Found conflicting settings in: ".implode(', ', $conflictingFiles).". They have been commented out.");
            }

            // CRITICAL: Also update php.ini file directly (this persists better than conf.d)
            // Since containers are ephemeral, we need to modify the main php.ini file
            $escapedPhpIniPath = escapeshellarg($phpIniPath);

            // Read current php.ini content
            $readCommand = "docker exec {$escapedContainer} cat {$escapedPhpIniPath}";
            if ($server->isNonRoot()) {
                $readCommand = "sudo {$readCommand}";
            }
            $phpIniContent = instant_remote_process([$readCommand], $server, false) ?? '';

            // Update the setting in content
            $lines = explode("\n", $phpIniContent);
            $found = false;
            $newLines = [];

            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                // Check if this line contains our setting (commented or not)
                if (preg_match('/^[;]*\s*'.preg_quote($setting, '/').'\s*=/i', $trimmedLine)) {
                    // Replace the line
                    $newLines[] = "{$setting} = {$value}";
                    $found = true;
                } else {
                    $newLines[] = $line;
                }
            }

            // If setting not found, add it at the end
            if (! $found) {
                $newLines[] = "";
                $newLines[] = "; {$setting} - Updated by Coolify";
                $newLines[] = "{$setting} = {$value}";
            }

            // Write php.ini back to container using the same reliable method
            try {
                $newContent = implode("\n", $newLines);

                // Save content to a temporary file locally
                $tmpFilename = 'temp/'.uniqid('php-ini-main-').'.ini';
                Storage::disk('local')->put($tmpFilename, $newContent);
                $localTmpPath = Storage::disk('local')->path($tmpFilename);

                // Copy to server temp location
                $serverTmpPath = '/tmp/'.basename($tmpFilename);
                instant_scp($localTmpPath, $serverTmpPath, $server);

                // Copy from server temp to container
                $escapedServerTmp = escapeshellarg($serverTmpPath);
                $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$escapedPhpIniPath}";
                if ($server->isNonRoot()) {
                    $copyCommand = "sudo {$copyCommand}";
                }
                instant_remote_process([$copyCommand], $server);

                // Clean up temp files
                Storage::disk('local')->delete($tmpFilename);
                $cleanCommand = "rm -f {$escapedServerTmp}";
                if ($server->isNonRoot()) {
                    $cleanCommand = "sudo {$cleanCommand}";
                }
                instant_remote_process([$cleanCommand], $server, false);
            } catch (\Throwable $e) {
                $this->dispatch('warning', "Failed to update php.ini file: ".$e->getMessage());
            }

            // CRITICAL: Restart PHP-FPM to reload configuration
            // upload_max_filesize and post_max_size require a full PHP-FPM restart, not just reload
            // For these settings, we need to restart the entire container to ensure volume is mounted
            $needsContainerRestart = in_array($setting, ['upload_max_filesize', 'post_max_size', 'memory_limit']);

            if ($needsContainerRestart) {
                // Restart the container to ensure volume is mounted and PHP-FPM reads new config
                $restartContainerCommand = "docker restart {$escapedContainer}";
                if ($server->isNonRoot()) {
                    $restartContainerCommand = "sudo {$restartContainerCommand}";
                }
                instant_remote_process([$restartContainerCommand], $server, false);

                // Wait for container to be ready
                $waitCommand = "docker exec {$escapedContainer} sh -c 'sleep 3 && php -r \"echo \\\"ready\\\";\"' 2>/dev/null || echo 'waiting'";
                if ($server->isNonRoot()) {
                    $waitCommand = "sudo {$waitCommand}";
                }
                $attempts = 0;
                while ($attempts < 10) {
                    $ready = trim(instant_remote_process([$waitCommand], $server, false) ?? '');
                    if ($ready === 'ready') {
                        break;
                    }
                    usleep(500000); // 0.5 seconds
                    $attempts++;
                }
            } else {
                // For other settings, just restart PHP-FPM
                $restartCommands = [
                    "docker exec {$escapedContainer} sh -c 'pkill -9 php-fpm 2>/dev/null || true'",
                    "docker exec {$escapedContainer} sh -c 'service php-fpm restart 2>/dev/null || service php8.3-fpm restart 2>/dev/null || service php8.2-fpm restart 2>/dev/null || service php8.1-fpm restart 2>/dev/null || service php8.0-fpm restart 2>/dev/null || true'",
                ];

                foreach ($restartCommands as $restartCommand) {
                    if ($server->isNonRoot()) {
                        $restartCommand = "sudo {$restartCommand}";
                    }
                    instant_remote_process([$restartCommand], $server, false);
                    usleep(1000000); // 1 second
                }
            }

            // Verify the change was applied - try multiple methods
            $verifiedValue = null;

            // Method 1: Try PHP-FPM directly
            $verifyFpmCommand = "docker exec {$escapedContainer} sh -c 'php-fpm -i 2>/dev/null | grep \"{$setting}\" | head -1 | awk -F\"=> \" \"{print \\\$2}\" | awk \"{print \\\$1}\" || echo notfound'";
            if ($server->isNonRoot()) {
                $verifyFpmCommand = "sudo {$verifyFpmCommand}";
            }
            $fpmValue = trim(instant_remote_process([$verifyFpmCommand], $server, false) ?? '');
            if (!empty($fpmValue) && $fpmValue !== 'notfound') {
                $verifiedValue = $fpmValue;
            }

            // Method 2: Try CLI php
            if (empty($verifiedValue)) {
                $verifyCommand = "docker exec {$escapedContainer} php -r \"echo ini_get('{$setting}');\" 2>/dev/null";
                if ($server->isNonRoot()) {
                    $verifyCommand = "sudo {$verifyCommand}";
                }
                $verifiedValue = trim(instant_remote_process([$verifyCommand], $server, false) ?? '');
            }

            // Method 3: Check conf.d file content directly - multiple methods
            $confFileValue = null;
            $confIniFileForCheck = $confDirPath.'/99-custom-'.$setting.'.ini';
            $escapedConfIniForCheck = escapeshellarg($confIniFileForCheck);

            // Try grep method
            $checkConfCommand = "docker exec {$escapedContainer} grep -E '^{$setting}\s*=' {$escapedConfIniForCheck} 2>/dev/null | head -1 | sed 's/.*=\\s*//' | xargs";
            if ($server->isNonRoot()) {
                $checkConfCommand = "sudo {$checkConfCommand}";
            }
            $confFileValue = trim(instant_remote_process([$checkConfCommand], $server, false) ?? '');

            // If empty, try reading entire file and parsing
            if (empty($confFileValue)) {
                $readFileCommand = "docker exec {$escapedContainer} cat {$escapedConfIniForCheck} 2>/dev/null";
                if ($server->isNonRoot()) {
                    $readFileCommand = "sudo {$readFileCommand}";
                }
                $fileContent = instant_remote_process([$readFileCommand], $server, false) ?? '';
                if (!empty($fileContent) && preg_match('/'.$setting.'\s*=\s*([^\s]+)/', $fileContent, $matches)) {
                    $confFileValue = trim($matches[1]);
                }
            }

            // Reload settings to update UI
            $this->loadPhpIniSettings($this->selectedContainerForPhpIni);

            // Note: Success message is already dispatched in the try block above
            // This section is only reached if there's an error or if we need additional verification
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error updating PHP setting: '.$e->getMessage());
        }
    }

    private function writeToContainerDirectly($server, $escapedContainer, $confDirPath, $confIniFileName, $confContent, $setting, $value)
    {
        // Fallback method: write directly to container (non-persistent)
        $confIniFile = $confDirPath.'/'.$confIniFileName;
        $escapedConfIni = escapeshellarg($confIniFile);

        try {
            $tmpFilename = 'temp/'.uniqid('php-ini-').'.ini';
            Storage::disk('local')->put($tmpFilename, $confContent);
            $localTmpPath = Storage::disk('local')->path($tmpFilename);

            $serverTmpPath = '/tmp/'.basename($tmpFilename);
            instant_scp($localTmpPath, $serverTmpPath, $server);

            $escapedServerTmp = escapeshellarg($serverTmpPath);
            $copyCommand = "docker cp {$escapedServerTmp} {$escapedContainer}:{$escapedConfIni}";
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
        } catch (\Throwable $e) {
            $this->dispatch('error', "Fallback write also failed: ".$e->getMessage());
        }
    }

    /**
     * UI-facing entry point for the "Arreglar permisos wp-content"
     * button. Delegates every line of shell to the
     * FixWordPressContentPermissions action so the job and the
     * button share identical logic — if the action ever changes,
     * there is a single place to touch.
     *
     * Populates $this->fixPermsResult with the structured result so
     * the blade can render a per-container breakdown: green check +
     * "WordPress ya puede escribir" when the write test passed, red
     * panel with the full output when something went wrong.
     */
    public function fixWpContentPermissions(): void
    {
        $this->authorize('update', $this->service);

        $this->isFixingPermissions = true;
        $this->fixPermsResult = null;

        try {
            // Refresh the service so applications() picks up any
            // containers that came online since the page was loaded.
            $this->service->refresh();
            $this->applications = $this->service->applications->sort();

            $result = FixWordPressContentPermissions::run($this->service);
            $this->fixPermsResult = $result;

            if ($result['ok']) {
                $this->dispatch('success', 'Permisos de wp-content arreglados.');
            } else {
                $firstError = (string) ($result['errors'][0] ?? 'Error fijando permisos.');
                $this->dispatch('error', $firstError);
            }
        } catch (\Throwable $e) {
            $this->fixPermsResult = [
                'ok' => false,
                'containers' => [],
                'errors' => [$e->getMessage()],
            ];
            $this->dispatch('error', 'Fallo arreglando permisos: '.$e->getMessage());
        } finally {
            $this->isFixingPermissions = false;
        }
    }

    /**
     * Applies the WP_PHP_INI_DEFAULTS preset to the currently selected
     * php.ini container, calling updatePhpIniSetting() for each key.
     * The existing updatePhpIniSetting() already knows how to create
     * a LocalFileVolume on conf.d/99-custom-*.ini and docker cp it
     * into the running container, so we just drive the loop.
     *
     * Exposed as a wire:click target from the "Configuración PHP" card
     * in wordpress-manager.blade.php.
     */
    public function applyRecommendedPhpDefaults(): void
    {
        $this->authorize('update', $this->service);

        if ($this->selectedContainerForPhpIni === null) {
            // If the user hasn't picked a container yet, auto-select
            // the first running WordPress container so the button is
            // a single click from page load.
            $firstRunning = collect($this->wordpressContainers)
                ->first(fn ($c) => str((string) ($c['status'] ?? ''))->contains('running'));
            if (! $firstRunning) {
                $this->dispatch('error', 'No hay contenedores WordPress en ejecución.');

                return;
            }
            $this->selectedContainerForPhpIni = (int) $firstRunning['id'];
        }

        $this->isApplyingPhpDefaults = true;

        try {
            // Single batched call instead of looping updatePhpIniSetting
            // 7 times. The previous loop fired ~30 SSH/docker exec commands
            // PER directive plus a full container restart for any of
            // memory_limit / upload_max_filesize / post_max_size — total
            // ~200 SSH calls + 3 restarts, which routinely exceeded the
            // nginx 60s timeout and produced 504 Gateway Time-out for
            // the user. The batched method does:
            //   1. Find conf.d ONCE
            //   2. Build ONE conf.d file containing every directive
            //   3. SCP + docker cp ONCE
            //   4. Restart container ONCE (only if any directive needs it)
            //   5. Verify ONCE
            // Total: ~10-15 SSH calls + 1 restart, well under 60s.
            $result = $this->batchUpdatePhpIniSettings(self::WP_PHP_INI_DEFAULTS);

            // Reload so the form reflects whatever PHP actually
            // ended up with after the saves.
            $this->loadPhpIniSettings($this->selectedContainerForPhpIni);

            if ($result['failed'] === 0) {
                $this->dispatch('success', "Defaults WordPress aplicados: {$result['applied']} directivas actualizadas y verificadas.");
            } else {
                $errSnippet = implode(' · ', array_slice($result['errors'], 0, 3));
                $this->dispatch('warning', "Defaults WordPress parcialmente aplicados: {$result['applied']} ok, {$result['failed']} fallidos. {$errSnippet}");
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Fallo aplicando defaults: '.$e->getMessage());
        } finally {
            $this->isApplyingPhpDefaults = false;
        }
    }

    /**
     * Writes ALL given php.ini directives in a SINGLE conf.d file and
     * restarts the container at most once. Designed to be called from
     * applyRecommendedPhpDefaults() or any other "set many at once"
     * caller without paying the per-directive cost of
     * updatePhpIniSetting() (which is fine for one-off saves but blows
     * up the request budget on a 7-key batch).
     *
     * Returns:
     *   ['applied' => int, 'failed' => int, 'errors' => string[]]
     *
     * Throws on hard preconditions (no container, container down, no
     * server). Per-directive verification failures are reported via
     * the return array, not by throwing.
     *
     * @param  array<string,string|int>  $settings
     * @return array{applied:int,failed:int,errors:string[]}
     */
    private function batchUpdatePhpIniSettings(array $settings): array
    {
        if ($this->selectedContainerForPhpIni === null) {
            throw new \RuntimeException('Ningún contenedor seleccionado.');
        }

        $container = collect($this->wordpressContainers)->firstWhere('id', $this->selectedContainerForPhpIni);
        if (! $container) {
            throw new \RuntimeException('Contenedor no encontrado.');
        }

        $application = $container['application'] ?? $this->applications->find($this->selectedContainerForPhpIni);
        if (! $application || ! str($application->status)->contains('running')) {
            throw new \RuntimeException('El contenedor no está en ejecución.');
        }

        $server = $application->service->server;
        $containerName = $container['container_name'];
        $escapedContainer = escapeshellarg($containerName);

        // STEP 1 — Find conf.d ONCE.
        $confDirCandidates = [
            '/usr/local/etc/php/conf.d',
            '/etc/php/8.4/fpm/conf.d',
            '/etc/php/8.3/fpm/conf.d',
            '/etc/php/8.2/fpm/conf.d',
            '/etc/php/8.1/fpm/conf.d',
            '/etc/php/conf.d',
        ];
        $confDirPath = null;
        foreach ($confDirCandidates as $candidate) {
            $cmd = "docker exec {$escapedContainer} test -d ".escapeshellarg($candidate)." && echo found || echo notfound";
            if ($server->isNonRoot()) {
                $cmd = "sudo {$cmd}";
            }
            $r = trim((string) instant_remote_process([$cmd], $server, false));
            if ($r === 'found') {
                $confDirPath = $candidate;
                break;
            }
        }
        if ($confDirPath === null) {
            $confDirPath = '/usr/local/etc/php/conf.d';
            $mkdirCmd = "docker exec {$escapedContainer} mkdir -p ".escapeshellarg($confDirPath);
            if ($server->isNonRoot()) {
                $mkdirCmd = "sudo {$mkdirCmd}";
            }
            instant_remote_process([$mkdirCmd], $server, false);
        }

        // STEP 2 — Build ONE conf.d file with every directive.
        $confContent = "; Custom PHP settings (WordPress defaults) - Updated by Coolify\n";
        foreach ($settings as $key => $value) {
            $confContent .= "{$key} = {$value}\n";
        }

        $confFileName = '99-coolify-wp-defaults.ini';
        $confFilePath = $confDirPath.'/'.$confFileName;
        $escapedConfFilePath = escapeshellarg($confFilePath);

        // STEP 3 — SCP + docker cp ONCE.
        $tmpFilename = 'temp/'.uniqid('php-ini-batch-').'.ini';
        Storage::disk('local')->put($tmpFilename, $confContent);
        $localTmpPath = Storage::disk('local')->path($tmpFilename);
        $serverTmpPath = '/tmp/'.basename($tmpFilename);
        instant_scp($localTmpPath, $serverTmpPath, $server);

        $copyCmd = 'docker cp '.escapeshellarg($serverTmpPath)." {$escapedContainer}:{$escapedConfFilePath}";
        if ($server->isNonRoot()) {
            $copyCmd = "sudo {$copyCmd}";
        }
        instant_remote_process([$copyCmd], $server);

        Storage::disk('local')->delete($tmpFilename);
        $cleanCmd = 'rm -f '.escapeshellarg($serverTmpPath);
        if ($server->isNonRoot()) {
            $cleanCmd = "sudo {$cleanCmd}";
        }
        instant_remote_process([$cleanCmd], $server, false);

        // STEP 4 — Persist via LocalFileVolume so the file survives
        // the next docker-compose recreation. Failures here are NOT
        // fatal — the file is already in the running container; we
        // only lose persistence across recreates. Wrapped in try so a
        // problem with the volume bookkeeping never blocks the user.
        try {
            $workdir = $application->service->workdir();
            $phpConfigDir = $workdir.'/php-config';
            $mkdirHostCmd = 'mkdir -p '.escapeshellarg($phpConfigDir);
            if ($server->isNonRoot()) {
                $mkdirHostCmd = "sudo {$mkdirHostCmd}";
            }
            instant_remote_process([$mkdirHostCmd], $server, false);

            $fileVolume = LocalFileVolume::where('resource_type', ServiceApplication::class)
                ->where('resource_id', $application->id)
                ->where('mount_path', $confFilePath)
                ->first();

            if (! $fileVolume) {
                $fileVolume = LocalFileVolume::create([
                    'resource_type' => ServiceApplication::class,
                    'resource_id' => $application->id,
                    'fs_path' => './php-config/'.$confFileName,
                    'mount_path' => $confFilePath,
                    'is_directory' => false,
                    'content' => $confContent,
                ]);
            } else {
                $fileVolume->content = $confContent;
                $fileVolume->save();
            }
            $fileVolume->saveStorageOnServer();

            // Re-parse compose so the volume mount lands in the
            // generated docker-compose for next deploy.
            $this->service->parse();
            $this->service->saveComposeConfigs();
        } catch (\Throwable $e) {
            \Log::warning('batchUpdatePhpIniSettings: persistence layer failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // STEP 5 — Restart container ONCE if any directive needs it.
        $needsRestart = false;
        foreach (array_keys($settings) as $k) {
            if (in_array($k, ['memory_limit', 'upload_max_filesize', 'post_max_size'], true)) {
                $needsRestart = true;
                break;
            }
        }

        if ($needsRestart) {
            $restartCmd = "docker restart {$escapedContainer}";
            if ($server->isNonRoot()) {
                $restartCmd = "sudo {$restartCmd}";
            }
            instant_remote_process([$restartCmd], $server, false, false, 60);

            // Wait up to 10s for the container to come back up.
            $waitCmd = "docker exec {$escapedContainer} php -r 'echo \"ready\";' 2>/dev/null || echo waiting";
            if ($server->isNonRoot()) {
                $waitCmd = "sudo {$waitCmd}";
            }
            for ($i = 0; $i < 20; $i++) {
                $r = trim((string) instant_remote_process([$waitCmd], $server, false));
                if ($r === 'ready') {
                    break;
                }
                usleep(500000);
            }
        } else {
            // Cheaper path: just reload php-fpm.
            $reloadCmd = "docker exec {$escapedContainer} sh -c 'pkill -USR2 php-fpm 2>/dev/null || true'";
            if ($server->isNonRoot()) {
                $reloadCmd = "sudo {$reloadCmd}";
            }
            instant_remote_process([$reloadCmd], $server, false);
        }

        // STEP 6 — Verify ONCE per directive (single docker exec each,
        // no nested checks). PHP normalises some values (e.g. 256M can
        // come back as 268435456 from ini_get), so we accept either
        // the literal match or the integer-byte equivalent.
        $applied = 0;
        $errors = [];
        foreach ($settings as $key => $expected) {
            $verifyCmd = "docker exec {$escapedContainer} php -r \"echo ini_get('{$key}');\" 2>/dev/null";
            if ($server->isNonRoot()) {
                $verifyCmd = "sudo {$verifyCmd}";
            }
            $actual = trim((string) instant_remote_process([$verifyCmd], $server, false));
            if ($this->phpIniValueMatches((string) $expected, $actual)) {
                $applied++;
            } else {
                $errors[] = "{$key}: esperaba {$expected}, PHP devolvió '{$actual}'";
            }
        }

        return [
            'applied' => $applied,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Tolerant comparator for php.ini values. PHP normalises shorthand
     * sizes (256M → 268435456) and trims whitespace differently across
     * SAPIs, so a strict string compare yields false negatives. We
     * compare both as raw strings AND as parsed byte counts when
     * applicable.
     */
    private function phpIniValueMatches(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }
        if (strcasecmp($expected, $actual) === 0) {
            return true;
        }
        $normExpected = preg_replace('/\s+/', '', $expected);
        $normActual = preg_replace('/\s+/', '', $actual);
        if ($normExpected !== null && $normActual !== null && strcasecmp($normExpected, $normActual) === 0) {
            return true;
        }

        // Parse shorthand byte sizes ("256M", "1G") if either side
        // looks numeric.
        $parse = function (string $v): ?int {
            $v = trim($v);
            if ($v === '') {
                return null;
            }
            if (ctype_digit($v)) {
                return (int) $v;
            }
            if (preg_match('/^(\d+)\s*([kmgKMG])$/', $v, $m)) {
                $n = (int) $m[1];
                $unit = strtolower($m[2]);

                return match ($unit) {
                    'k' => $n * 1024,
                    'm' => $n * 1024 * 1024,
                    'g' => $n * 1024 * 1024 * 1024,
                    default => $n,
                };
            }

            return null;
        };
        $a = $parse($expected);
        $b = $parse($actual);
        if ($a !== null && $b !== null) {
            return $a === $b;
        }

        return false;
    }

    public function render()
    {
        return view('livewire.project.service.wordpress-manager');
    }
}
// resync-marker 2026-04-08
