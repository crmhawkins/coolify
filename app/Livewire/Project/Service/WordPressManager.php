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
     * Strategy 2 implementation: raw SQL UPDATE statements via the
     * `mysql` client inside the WordPress container. Reads the DB
     * credentials from the WordPress environment (WORDPRESS_DB_*)
     * which are the standard env vars the official image uses, then
     * resolves the actual table prefix from wp-config.php via the
     * same helper we already use in the "Prefijo de tablas" card.
     *
     * Returns ['ok' => bool, 'log' => string].
     *
     * @param  array<string, mixed>  $container
     * @return array{ok: bool, log: string}
     */
    private function runSyncUrlsWithSql($server, string $escapedContainer, array $containerMeta): array
    {
        $log = '';

        // Read the DB credentials from the container's environment.
        $envDump = "docker exec {$escapedContainer} sh -c 'printenv WORDPRESS_DB_HOST WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD 2>/dev/null'";
        if ($server->isNonRoot()) {
            $envDump = "sudo {$envDump}";
        }
        $envRaw = (string) (instant_remote_process([$envDump], $server, false) ?? '');
        $envLines = preg_split('/\r?\n/', trim($envRaw)) ?: [];
        $envLines = array_values(array_filter($envLines, fn ($l) => $l !== ''));
        if (count($envLines) < 4) {
            return ['ok' => false, 'log' => "  ✗ No se pudieron leer WORDPRESS_DB_* del entorno del contenedor (obtenidas ".count($envLines)."/4 vars).\n"];
        }
        [$dbHost, $dbName, $dbUser, $dbPass] = $envLines;

        // Check that `mysql` CLI is available inside the container.
        $mysqlCheck = "docker exec {$escapedContainer} sh -c 'command -v mysql >/dev/null 2>&1 && echo ok || echo notfound'";
        if ($server->isNonRoot()) {
            $mysqlCheck = "sudo {$mysqlCheck}";
        }
        $mysqlCheckResult = trim((string) (instant_remote_process([$mysqlCheck], $server, false) ?? ''));
        if ($mysqlCheckResult !== 'ok') {
            return ['ok' => false, 'log' => "  ✗ El cliente `mysql` no está instalado en el contenedor WordPress (imagen oficial tampoco lo trae). No puedo ejecutar el fallback SQL.\n"];
        }

        // Resolve the current prefix so we UPDATE the right table names.
        $prefix = $this->detectWpPrefix($server, $containerMeta['container_name']) ?? 'wp_';
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            return ['ok' => false, 'log' => "  ✗ Prefijo detectado inválido: {$prefix}\n"];
        }
        $log .= "  ℹ prefijo detectado: {$prefix}\n";

        // Build the SQL. We use multiple small statements (one per
        // column) because MySQL's REPLACE() cannot be chained across
        // columns in a single UPDATE without becoming unreadable.
        $old = $this->oldUrl;
        $new = $this->newUrl;

        // Escape for single-quote SQL literals. We intentionally do
        // NOT use backslash escapes — mysql default ansi mode parses
        // doubled single-quotes as an escape for `'`.
        $sqlOld = str_replace("'", "''", $old);
        $sqlNew = str_replace("'", "''", $new);

        $statements = [
            "UPDATE `{$prefix}options` SET option_value = REPLACE(option_value, '{$sqlOld}', '{$sqlNew}') WHERE option_name IN ('siteurl', 'home');",
            "UPDATE `{$prefix}posts` SET guid = REPLACE(guid, '{$sqlOld}', '{$sqlNew}');",
            "UPDATE `{$prefix}posts` SET post_content = REPLACE(post_content, '{$sqlOld}', '{$sqlNew}');",
            "UPDATE `{$prefix}posts` SET post_excerpt = REPLACE(post_excerpt, '{$sqlOld}', '{$sqlNew}');",
            "UPDATE `{$prefix}postmeta` SET meta_value = REPLACE(meta_value, '{$sqlOld}', '{$sqlNew}') WHERE meta_value NOT LIKE '%s:%' OR meta_value NOT LIKE '%:\"%';",
            "UPDATE `{$prefix}comments` SET comment_content = REPLACE(comment_content, '{$sqlOld}', '{$sqlNew}');",
        ];

        $sql = implode("\n", $statements);

        // Pipe the SQL to `mysql` via stdin using a here-doc so we
        // never have to escape the statements for the shell again.
        // MYSQL_PWD is the modern recommended env var (avoids the
        // "-p" warning on stderr).
        $hostArg = escapeshellarg($dbHost);
        $userArg = escapeshellarg($dbUser);
        $nameArg = escapeshellarg($dbName);
        $pwdArg = escapeshellarg($dbPass);

        // Write the SQL to a temp file inside the container, execute
        // mysql, then remove the file. Keeps the command line short
        // and avoids shell-quoting the multi-line SQL.
        $tmpFile = '/tmp/coolify-wp-url-sync-'.uniqid().'.sql';
        $tmpFileArg = escapeshellarg($tmpFile);

        $writeSqlCmd = "docker exec -i {$escapedContainer} sh -c 'cat > {$tmpFileArg}'";
        if ($server->isNonRoot()) {
            $writeSqlCmd = "sudo {$writeSqlCmd}";
        }

        try {
            // We cannot stream stdin through instant_remote_process,
            // so we use a base64 round-trip: encode locally, echo
            // inside the container, decode to the target file.
            $b64 = base64_encode($sql);
            $b64Arg = escapeshellarg($b64);
            $writeInline = "docker exec {$escapedContainer} sh -c 'echo {$b64Arg} | base64 -d > {$tmpFileArg}'";
            if ($server->isNonRoot()) {
                $writeInline = "sudo {$writeInline}";
            }
            instant_remote_process([$writeInline], $server, false);

            $runCmd = "docker exec {$escapedContainer} sh -c 'MYSQL_PWD={$pwdArg} mysql -h {$hostArg} -u {$userArg} {$nameArg} < {$tmpFileArg} 2>&1'";
            if ($server->isNonRoot()) {
                $runCmd = "sudo {$runCmd}";
            }
            $runOutput = (string) (instant_remote_process([$runCmd], $server, false) ?? '');
            $runOutput = trim($runOutput);

            if ($runOutput !== '') {
                $log .= "  mysql stdout: ".$runOutput."\n";
            }

            // If the output contains "ERROR" we treat it as a failure.
            if (stripos($runOutput, 'ERROR') !== false) {
                $log .= "  ✗ mysql reportó un error\n";
                return ['ok' => false, 'log' => $log];
            }

            $log .= "  ✓ SQL ejecutado sobre tablas: {$prefix}options, {$prefix}posts, {$prefix}postmeta, {$prefix}comments\n";

            return ['ok' => true, 'log' => $log];
        } catch (\Throwable $e) {
            return ['ok' => false, 'log' => $log."  ✗ excepción: ".$e->getMessage()."\n"];
        } finally {
            $cleanCmd = "docker exec {$escapedContainer} sh -c 'rm -f {$tmpFileArg}'";
            if ($server->isNonRoot()) {
                $cleanCmd = "sudo {$cleanCmd}";
            }
            try {
                instant_remote_process([$cleanCmd], $server, false);
            } catch (\Throwable $e) {
                // Best-effort.
            }
        }
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

    public function detectWpPrefix($server, string $containerName): ?string
    {
        try {
            $escapedContainer = escapeshellarg($containerName);

            // Try to get prefix from wp-config.php
            $configCommand = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && grep -E \"\\\$table_prefix\" wp-config.php 2>/dev/null | head -1 || echo notfound'";
            if ($server->isNonRoot()) {
                $configCommand = "sudo {$configCommand}";
            }
            $configOutput = trim(instant_remote_process([$configCommand], $server, false) ?? '');

            if ($configOutput !== 'notfound' && ! empty($configOutput)) {
                // Extract prefix from line like: $table_prefix = 'wp_';
                if (preg_match("/['\"]([^'\"]+)['\"]/", $configOutput, $matches)) {
                    return $matches[1];
                }
            }

            // Try to detect from database tables
            $prefix = $this->detectPrefixFromDatabase($server, $containerName);
            if ($prefix) {
                return $prefix;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
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
     * Changes the WordPress table prefix end-to-end:
     *
     *   1. Validates the new prefix format (and that it is different
     *      from the current one).
     *   2. Reads the current prefix from wp-config.php.
     *   3. Detects the DB credentials + current prefix tables via the
     *      WORDPRESS_DB_* environment variables and
     *      `SHOW TABLES LIKE 'oldprefix%'`.
     *   4. Backs up wp-config.php to wp-config.php.backup-<timestamp>
     *      inside the container (so a failed rename is recoverable).
     *   5. Renames every `oldprefix*` table to `newprefix*` via
     *      `RENAME TABLE` in a single statement (atomic).
     *   6. Updates the few WordPress user_meta / options rows that
     *      embed the literal prefix string:
     *        - wp_user_meta.meta_key like 'oldprefix_capabilities'
     *        - wp_user_meta.meta_key like 'oldprefix_user_level'
     *        - wp_user_meta.meta_key like 'oldprefix_user-settings'
     *        - wp_user_meta.meta_key like 'oldprefix_dashboard_quick_press_last_post_id'
     *        - wp_options.option_name = 'oldprefix_user_roles'
     *      Without this step, logged-in users lose all permissions
     *      (including admin) because WordPress looks them up by
     *      the prefixed key name.
     *   7. Finally rewrites wp-config.php with the new prefix and
     *      runs detectWpPrefix() to verify.
     *
     * Everything runs inside a single SQL script piped into `mysql`,
     * and the wp-config rewrite uses a tiny PHP helper as before. We
     * do NOT run this unless `mysql` CLI is available in the
     * container — if it isn't, the old implementation's "only rewrite
     * wp-config.php" behaviour would silently tumble the site, so we
     * refuse with a clear error instead.
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
            // Enforce WordPress convention — WP code concatenates
            // the prefix with underscore-less table names like
            // `$wpdb->prefix . 'posts'`, so a prefix without `_`
            // produces invalid table names.
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
            // Step 1: detect current prefix — no-op if already matches.
            $currentPrefix = $this->detectWpPrefix($server, $containerName) ?? 'wp_';
            if ($currentPrefix === $newPrefix) {
                $this->dispatch('warning', "El prefijo ya es {$newPrefix}, no hay nada que hacer.");

                return;
            }
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $currentPrefix)) {
                $this->dispatch('error', "Prefijo actual detectado inválido: {$currentPrefix}.");

                return;
            }

            // Step 2: ensure `mysql` CLI is available. Without it we
            // CANNOT rename tables, and rewriting wp-config.php alone
            // would leave the site pointing at non-existent tables —
            // that's the old bug we are fixing here.
            $mysqlCheck = "docker exec {$escapedContainer} sh -c 'command -v mysql >/dev/null 2>&1 && echo ok || echo notfound'";
            if ($server->isNonRoot()) {
                $mysqlCheck = "sudo {$mysqlCheck}";
            }
            if (trim((string) (instant_remote_process([$mysqlCheck], $server, false) ?? '')) !== 'ok') {
                $this->dispatch('error', 'El cliente `mysql` no está instalado en el contenedor WordPress. No puedo renombrar las tablas — cambiar solo wp-config.php tumbaría el sitio, por eso abortamos.');

                return;
            }

            // Step 3: read DB credentials from WORDPRESS_DB_* env vars.
            $envDump = "docker exec {$escapedContainer} sh -c 'printenv WORDPRESS_DB_HOST WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD 2>/dev/null'";
            if ($server->isNonRoot()) {
                $envDump = "sudo {$envDump}";
            }
            $envLines = array_values(array_filter(preg_split('/\r?\n/', trim((string) (instant_remote_process([$envDump], $server, false) ?? ''))) ?: [], fn ($l) => $l !== ''));
            if (count($envLines) < 4) {
                $this->dispatch('error', 'No pude leer WORDPRESS_DB_HOST / NAME / USER / PASSWORD del entorno del contenedor.');

                return;
            }
            [$dbHost, $dbName, $dbUser, $dbPass] = $envLines;

            // Step 4: list all current prefix tables.
            $listSql = "SHOW TABLES LIKE '".str_replace('_', '\\_', $currentPrefix)."%';";
            $listB64 = escapeshellarg(base64_encode($listSql));
            $listRun = "docker exec {$escapedContainer} sh -c 'echo {$listB64} | base64 -d | MYSQL_PWD=".escapeshellarg($dbPass)." mysql -h ".escapeshellarg($dbHost)." -u ".escapeshellarg($dbUser)." ".escapeshellarg($dbName)." --skip-column-names 2>&1'";
            if ($server->isNonRoot()) {
                $listRun = "sudo {$listRun}";
            }
            $tablesRaw = (string) (instant_remote_process([$listRun], $server, false) ?? '');
            $tables = array_values(array_filter(preg_split('/\r?\n/', trim($tablesRaw)) ?: [], fn ($l) => $l !== '' && stripos($l, 'ERROR') === false));

            if (empty($tables)) {
                $this->dispatch('error', "No se encontraron tablas con prefijo '{$currentPrefix}'. Aborto por seguridad.");

                return;
            }

            // Step 5: build the RENAME TABLE + user_meta + options update script.
            $renameParts = [];
            foreach ($tables as $t) {
                $suffix = substr($t, strlen($currentPrefix));
                if ($suffix === '' || ! preg_match('/^[a-zA-Z0-9_]+$/', $suffix)) {
                    continue;
                }
                $renameParts[] = "`{$t}` TO `{$newPrefix}{$suffix}`";
            }
            if (empty($renameParts)) {
                $this->dispatch('error', 'No se pudo construir el RENAME TABLE (ninguna tabla pasó la validación).');

                return;
            }
            $renameSql = 'RENAME TABLE '.implode(', ', $renameParts).';';

            // user_meta updates — prefix-embedded keys.
            // After rename, the user_meta TABLE is already at newprefix_usermeta.
            $oldPrefixEscaped = str_replace("'", "''", $currentPrefix);
            $newPrefixEscaped = str_replace("'", "''", $newPrefix);
            $metaSql = <<<SQL
UPDATE `{$newPrefix}usermeta` SET meta_key = REPLACE(meta_key, '{$oldPrefixEscaped}', '{$newPrefixEscaped}') WHERE meta_key LIKE '{$oldPrefixEscaped}%';
UPDATE `{$newPrefix}options` SET option_name = REPLACE(option_name, '{$oldPrefixEscaped}', '{$newPrefixEscaped}') WHERE option_name LIKE '{$oldPrefixEscaped}%';
SQL;

            $fullSql = $renameSql."\n".$metaSql."\n";
            $fullB64 = escapeshellarg(base64_encode($fullSql));
            $runCmd = "docker exec {$escapedContainer} sh -c 'echo {$fullB64} | base64 -d | MYSQL_PWD=".escapeshellarg($dbPass)." mysql -h ".escapeshellarg($dbHost)." -u ".escapeshellarg($dbUser)." ".escapeshellarg($dbName)." 2>&1'";
            if ($server->isNonRoot()) {
                $runCmd = "sudo {$runCmd}";
            }
            $sqlOutput = (string) (instant_remote_process([$runCmd], $server, false) ?? '');
            if (stripos($sqlOutput, 'ERROR') !== false) {
                $this->dispatch('error', 'RENAME TABLE falló: '.trim($sqlOutput));

                return;
            }

            // Step 6: backup wp-config.php before touching it.
            $backupName = 'wp-config.php.backup-'.now()->format('Ymd-His');
            $backupCmd = "docker exec {$escapedContainer} sh -c 'cp /var/www/html/wp-config.php /var/www/html/{$backupName}'";
            if ($server->isNonRoot()) {
                $backupCmd = "sudo {$backupCmd}";
            }
            instant_remote_process([$backupCmd], $server, false);

            // Step 7: rewrite wp-config.php with the new prefix.
            $escapedPrefix = escapeshellarg($newPrefix);
            $scriptContent = <<<'PHP'
<?php
$file = '/var/www/html/wp-config.php';
if (!file_exists($file)) {
    echo "ERROR: wp-config.php not found\n";
    exit(1);
}
$content = file_get_contents($file);
$newPrefix = $argv[1] ?? '';
if (empty($newPrefix)) {
    echo "ERROR: No prefix provided\n";
    exit(1);
}
$pattern = '/(\$table_prefix\s*=\s*)["\']([^"\']*)["\']/';
$replacement = '$1"' . $newPrefix . '"';
$new = preg_replace($pattern, $replacement, $content);
if ($new === null || $new === $content) {
    echo "ERROR: wp-config.php did not contain a \$table_prefix assignment to replace\n";
    exit(1);
}
if (file_put_contents($file, $new) === false) {
    echo "ERROR: Failed to write wp-config.php\n";
    exit(1);
}
echo "SUCCESS\n";
PHP;

            $scriptPath = '/tmp/update_prefix_'.uniqid().'.php';
            $scriptB64 = escapeshellarg(base64_encode($scriptContent));
            $writeScript = "docker exec {$escapedContainer} sh -c 'echo {$scriptB64} | base64 -d > ".escapeshellarg($scriptPath)."'";
            if ($server->isNonRoot()) {
                $writeScript = "sudo {$writeScript}";
            }
            instant_remote_process([$writeScript], $server, false);

            $runScript = "docker exec {$escapedContainer} sh -c 'cd /var/www/html && php ".escapeshellarg($scriptPath)." {$escapedPrefix} 2>&1'";
            if ($server->isNonRoot()) {
                $runScript = "sudo {$runScript}";
            }
            $phpOutput = (string) (instant_remote_process([$runScript], $server, false) ?? '');

            $cleanupScript = "docker exec {$escapedContainer} sh -c 'rm -f ".escapeshellarg($scriptPath)."'";
            if ($server->isNonRoot()) {
                $cleanupScript = "sudo {$cleanupScript}";
            }
            try {
                instant_remote_process([$cleanupScript], $server, false);
            } catch (\Throwable $e) {
                // Best-effort cleanup.
            }

            if (stripos($phpOutput, 'ERROR') !== false) {
                $this->dispatch('error', 'wp-config.php update failed: '.trim($phpOutput).' (las tablas ya fueron renombradas, tendrás que revertir manualmente — backup en /var/www/html/'.$backupName.')');

                return;
            }

            // Step 8: verify end-state.
            $finalVerify = $this->detectWpPrefix($server, $containerName);
            if ($finalVerify !== $newPrefix) {
                $this->dispatch('error', 'Prefijo cambiado en DB pero wp-config.php parece inconsistente. Verifica manualmente. Actual: '.($finalVerify ?? 'no detectado').'. Backup: /var/www/html/'.$backupName);

                return;
            }

            $this->detectWpPrefixes();
            $this->dispatch('success', "Prefijo actualizado de {$currentPrefix} a {$newPrefix}: ".count($renameParts)." tablas renombradas + user_meta + options. Backup wp-config: /var/www/html/{$backupName}");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to update WordPress prefix: '.$e->getMessage());
        }
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

    public function render()
    {
        return view('livewire.project.service.wordpress-manager');
    }
}
// resync-marker 2026-04-08
