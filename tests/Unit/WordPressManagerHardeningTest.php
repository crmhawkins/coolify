<?php

use App\Actions\Service\FixWordPressContentPermissions;

/*
|--------------------------------------------------------------------------
| WordPress Manager hardening tests
|--------------------------------------------------------------------------
|
| Pure-logic unit tests for the WordPress Manager overhaul. Covers the
| FixWordPressContentPermissions action (chown/chmod script builder +
| sentinel wrapper), the team scoping on WordPressManager::mount(),
| the Heading auto-run hook wiring, and the updateWpPrefix / syncUrls
| SQL fallbacks. No SSH, no mysql, no docker exec — everything is a
| file_get_contents assertion or a direct static call.
|
| Run: php artisan test --compact --filter=WordPressManagerHardening
|
*/

/* -----------------------------------------------------------------
 | Action: FixWordPressContentPermissions
 | ----------------------------------------------------------------- */

it('builds an inner script with every step from the briefing', function () {
    $script = FixWordPressContentPermissions::make()->buildInnerScript();

    expect($script)
        // Core chown + chmod chain
        ->toContain('cd /var/www/html')
        ->toContain('chown -R www-data:www-data wp-content')
        ->toContain("find wp-content -type d -exec chmod 755 {} +")
        ->toContain("find wp-content -type f -exec chmod 644 {} +")
        // wp-content/upgrade block (the bit the user's manual fix
        // had that was originally missing from the private
        // fixPermissions() method).
        ->toContain('mkdir -p wp-content/upgrade')
        ->toContain('chown www-data:www-data wp-content/upgrade')
        ->toContain('chmod 755 wp-content/upgrade')
        // All three verification steps from the briefing
        ->toContain("ls -ld wp-content")
        ->toContain("stat -c '%U:%G' wp-content/upgrade")
        ->toContain('su -s /bin/bash www-data -c "touch wp-content/.coolify-write-test && rm wp-content/.coolify-write-test"')
        // Write-test sentinel used by the PHP parser
        ->toContain('__WRITE_TEST_OK__');
});

it('injects FS_METHOD=direct into wp-config.php before touching permissions', function () {
    $script = FixWordPressContentPermissions::make()->buildInnerScript();

    expect($script)
        // Lock the exact define literal so nothing downstream rewrites
        // it into a variation WordPress no longer recognises.
        ->toContain("define('FS_METHOD', 'direct');")
        // Idempotency guard: the grep check must run before the append
        // so a second invocation is a no-op and wp-config.php does not
        // accumulate duplicate defines.
        ->toContain('grep -q FS_METHOD wp-config.php')
        // Backup must happen before the edit so a panicked operator
        // can always undo. Timestamped suffix keeps every run distinct.
        ->toContain('wp-config.php.coolify-bk-$(date +%s)')
        // User-facing output the Livewire panel prints to confirm the
        // three possible branches (added / already present / missing).
        ->toContain('FS_METHOD=direct (backup saved)')
        ->toContain('FS_METHOD already present')
        ->toContain('not found, skipping FS_METHOD injection');

    // Ordering: the FS_METHOD block must sit BEFORE the chown block,
    // so wp-config.php already forces direct mode by the time the
    // permissions step completes and the admin UI reloads.
    $fsPos = strpos($script, 'FS_METHOD');
    $chownPos = strpos($script, 'chown -R www-data:www-data wp-content');
    expect($fsPos)->toBeGreaterThan(0);
    expect($chownPos)->toBeGreaterThan(0);
    expect($fsPos)->toBeLessThan($chownPos);
});

it('exposes a stable failure sentinel constant', function () {
    expect(FixWordPressContentPermissions::FAILURE_SENTINEL)
        ->toBe('__COOLIFY_FIX_WP_PERMS_FAILED__');
});

it('detects WordPress containers from image / name / env vars', function () {
    $action = FixWordPressContentPermissions::make();

    // Image name match
    $wp1 = (object) ['image' => 'wordpress:latest', 'name' => 'web'];
    $wp1->environment_variables = fn () => collect([]);
    expect($action->looksLikeWordPress($wp1))->toBeTrue();

    // Container name match
    $wp2 = (object) ['image' => 'custom/php-apache', 'name' => 'wordpress-prod'];
    $wp2->environment_variables = fn () => collect([]);
    expect($action->looksLikeWordPress($wp2))->toBeTrue();

    // Non-match
    $other = (object) ['image' => 'mariadb:11', 'name' => 'db'];
    $other->environment_variables = fn () => collect([]);
    expect($action->looksLikeWordPress($other))->toBeFalse();
});

/* -----------------------------------------------------------------
 | L8-style IDOR: team scoping on WordPressManager::mount()
 | ----------------------------------------------------------------- */

it('scopes Service::whereUuid to the current team in WordPressManager::mount()', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    expect($source)
        ->toContain('Service::ownedByCurrentTeam()')
        ->not->toMatch('/Service::whereUuid\(request\(\)->route/');
});

/* -----------------------------------------------------------------
 | Heading auto-run hook
 | ----------------------------------------------------------------- */

it('wires the WordPress permissions auto-run into Heading::redeploy and pullAndRestartEvent', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/Heading.php');

    expect($source)
        ->toContain('use App\\Jobs\\FixWordPressContentPermissionsJob;')
        ->toContain('use App\\Actions\\Service\\FixWordPressContentPermissions;')
        ->toContain('private function scheduleWordPressPermissionsFix')
        ->toContain('FixWordPressContentPermissionsJob::dispatch($this->service->id)')
        // Both triggering actions call the private helper.
        ->toMatch('/public function redeploy\(\).*\$this->scheduleWordPressPermissionsFix\(\)/s')
        ->toMatch('/public function pullAndRestartEvent\(\).*\$this->scheduleWordPressPermissionsFix\(\)/s');
});

it('does NOT wire the auto-run into Heading::restart (docker restart, no rebuild)', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/Heading.php');

    // Extract just the restart() method body and assert the hook is
    // absent. We do this by capturing text between `public function
    // restart()` and the next `public function` declaration.
    preg_match('/public function restart\(\).*?(?=public function )/s', $source, $m);
    expect(isset($m[0]))->toBeTrue('restart() method should exist');
    expect($m[0])->not->toContain('scheduleWordPressPermissionsFix');
});

/* -----------------------------------------------------------------
 | Job: FixWordPressContentPermissionsJob
 | ----------------------------------------------------------------- */

it('job retries up to 3 times with exponential backoff', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/FixWordPressContentPermissionsJob.php');

    expect($source)
        ->toContain('public int $tries = 3;')
        ->toContain('return [15, 30, 60];')
        ->toContain('public int $timeout = 300;')
        // The job must hydrate the Service from the id to pick up
        // fresh container status after a redeploy.
        ->toContain('Service::find($this->serviceId)')
        ->toContain('$service->load(\'applications\')');
});

/* -----------------------------------------------------------------
 | syncUrls: WP-CLI + SQL fallback
 | ----------------------------------------------------------------- */

it('syncUrls SQL fallback uses PHP + mysqli and covers the canonical URL tables', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    expect($source)
        ->toContain('private function isWpCliAvailable')
        ->toContain('private function runSyncUrlsWithWpCli')
        ->toContain('private function runSyncUrlsWithSql')
        ->toContain('buildSyncUrlsPhpScript')
        // Updates options, posts, postmeta, comments.
        ->toContain("\$prefix . 'options'")
        ->toContain("\$prefix . 'posts'")
        ->toContain("\$prefix . 'postmeta'")
        ->toContain("\$prefix . 'comments'")
        // Excludes PHP-serialized blobs that can't be safely updated.
        ->toContain('NOT REGEXP')
        // Warns about the serialized-data limitation in the user-facing
        // output of syncUrls() itself.
        ->toContain('datos serializados');
});

/* -----------------------------------------------------------------
 | updateWpPrefix: atomic RENAME TABLE + user_meta fix
 | ----------------------------------------------------------------- */

it('updateWpPrefix uses PHP + mysqli inside the container instead of the mysql CLI', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    // The old implementation relied on the mysql client which is
    // not present in the official WordPress image. The rewrite
    // uses a PHP helper script that opens mysqli — a hard
    // requirement for WordPress itself, so always available.
    expect($source)
        ->toContain('buildUpdatePrefixPhpScript')
        ->toContain('runPhpScriptInContainer')
        ->toContain('new mysqli(')
        ->toContain('$mysqli->begin_transaction()')
        // And the old mysql-CLI-based abort is gone.
        ->not->toContain("'No puedo renombrar las tablas'");
});

it('updateWpPrefix script covers RENAME TABLE + usermeta + options in a single transaction', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    expect($source)
        ->toContain('RENAME TABLE ')
        ->toContain("SHOW TABLES LIKE '")
        ->toContain("\$newPrefix . 'usermeta'")
        ->toContain("\$newPrefix . 'options'")
        // Wraps everything in a transaction with rollback on error.
        ->toContain('begin_transaction')
        ->toContain('$mysqli->rollback')
        // Backs up wp-config.php before touching it.
        ->toContain('wp-config.php.backup-')
        // Rejects prefixes without trailing underscore (WP convention).
        ->toContain('debe terminar en un guion bajo');
});

it('updateWpPrefix handles the "tables already have the new prefix" no-op case', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    // When the user wrote a different prefix in wp-config.php already
    // and only wants Coolify to rename the tables to match, our
    // script should also handle the inverse: tables already match,
    // just update wp-config.php.
    expect($source)
        ->toContain('Tables already use the new prefix');
});

it('DB credential resolver uses env vars first, then literal defines, then null', function () {
    // The official wordpress:latest Docker image uses getenv_docker()
    // calls in wp-config.php instead of literal strings. Our helper
    // scripts must resolve WORDPRESS_DB_* env vars first (which the
    // container has) and fall back to the literal define() regex
    // only for custom installs that hard-code credentials.
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    expect($source)
        // The resolver function exists and is called in BOTH scripts.
        ->toContain('function resolve_db_var')
        // Strategy 1: env vars
        ->toContain("\$envName = 'WORDPRESS_' . \$name;")
        ->toContain('getenv($envName)')
        ->toContain('$_ENV[$envName]')
        ->toContain('$_SERVER[$envName]')
        // Strategy 2: literal define regex
        ->toContain("preg_match('/define\\s*\\(")
        // Better error message listing missing constants.
        ->toContain('Could not resolve DB credentials')
        ->toContain('WORDPRESS_DB_*');

    // Both scripts must call resolve_db_var — not the old
    // extract_define name.
    expect(substr_count($source, "function resolve_db_var"))->toBe(2);
    expect(substr_count($source, 'extract_define'))->toBe(0);
});

/* -----------------------------------------------------------------
 | Blade: sub-menu + permissions card
 | ----------------------------------------------------------------- */

it('wordpress-manager blade includes the full service sub-menu (not just General + Manager)', function () {
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/wordpress-manager.blade.php');

    // Key sub-menu items that were missing in the old truncated version.
    expect($blade)
        ->toContain('Environment Variables')
        ->toContain('Persistent Storages')
        ->toContain('Scheduled Tasks')
        ->toContain('Webhooks')
        ->toContain('Resource Operations')
        ->toContain('Tags')
        ->toContain('Danger Zone');
});

it('wordpress-manager blade exposes the Arreglar permisos button', function () {
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/wordpress-manager.blade.php');

    expect($blade)
        ->toContain('wire:click="fixWpContentPermissions"')
        ->toContain('wire:confirm="¿Ajustar owner')
        ->toContain('Arreglar permisos')
        // Notes that the auto-run on redeploy is active.
        ->toContain('Redeploy')
        ->toContain('Pull &amp; Restart');
});

it('wordpress-manager blade avoids Tailwind arbitrary-value classes that break on uncompiled bundles', function () {
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/wordpress-manager.blade.php');

    // Lesson learned from d15ba255d (compression tasks icon gigante):
    // avoid w-[...] / h-[...] arbitrary values because the Tailwind
    // JIT may not have compiled them for this file yet. Use inline
    // width/height style attributes instead.
    expect($blade)
        ->not->toMatch('/class="[^"]*w-\[[^\]]+\]/')
        ->not->toMatch('/class="[^"]*h-\[[^\]]+\]/');
});

/* -----------------------------------------------------------------
 | Recommended WordPress php.ini defaults
 | ----------------------------------------------------------------- */

it('WordPressManager exposes the WP_PHP_INI_DEFAULTS constant with sensible values', function () {
    $defaults = \App\Livewire\Project\Service\WordPressManager::WP_PHP_INI_DEFAULTS;

    expect($defaults)->toBeArray();
    expect($defaults['upload_max_filesize'] ?? null)->toBe('256M');
    expect($defaults['post_max_size'] ?? null)->toBe('256M');
    expect($defaults['memory_limit'] ?? null)->toBe('512M');
    expect($defaults['max_execution_time'] ?? null)->toBe('300');
    expect($defaults['max_input_time'] ?? null)->toBe('300');
    expect($defaults['max_input_vars'] ?? null)->toBe('5000');
    expect($defaults['max_file_uploads'] ?? null)->toBe('50');
});

it('applyRecommendedPhpDefaults is exposed as a public wire:click target', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/wordpress-manager.blade.php');

    expect($source)
        ->toContain('public function applyRecommendedPhpDefaults')
        ->toContain('public bool $isApplyingPhpDefaults')
        ->toContain('self::WP_PHP_INI_DEFAULTS');

    expect($blade)
        ->toContain('wire:click="applyRecommendedPhpDefaults"')
        ->toContain('wire:confirm="¿Aplicar los defaults recomendados')
        ->toContain('Aplicar defaults WordPress');
});

it('SetupWordPress seeds the recommended php.ini defaults on first setup', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Service/SetupWordPress.php');

    expect($source)
        ->toContain('seedRecommendedPhpIniDefaults')
        ->toContain('WordPressManager::WP_PHP_INI_DEFAULTS')
        ->toContain('LocalFileVolume')
        ->toContain('99-custom-')
        ->toContain('/usr/local/etc/php/conf.d/')
        // Soft reloads PHP-FPM so the new conf.d files take effect.
        ->toContain('pkill -USR2 php-fpm');
});

/* -----------------------------------------------------------------
 | parseTablePrefixFromConfig: ignores commented examples
 | ----------------------------------------------------------------- */

it('parseTablePrefixFromConfig ignores commented multisite example', function () {
    // The WordPress default wp-config.php ships with a comment that
    // contains `$table_prefix = '...';` as an example. The old grep
    // approach matched this line too and head -1 would pick it.
    $content = "<?php\n/**\n * Example:\n * \$table_prefix = 'example_';\n */\n\$table_prefix = 'jz8i7ogy_';\n";

    $prefix = \App\Livewire\Project\Service\WordPressManager::parseTablePrefixFromConfig($content);
    expect($prefix)->toBe('jz8i7ogy_');
});

it('parseTablePrefixFromConfig handles both quote styles', function () {
    expect(\App\Livewire\Project\Service\WordPressManager::parseTablePrefixFromConfig("<?php\n\$table_prefix = 'wp_';"))
        ->toBe('wp_');
    expect(\App\Livewire\Project\Service\WordPressManager::parseTablePrefixFromConfig("<?php\n\$table_prefix = \"wp_\";"))
        ->toBe('wp_');
});

it('parseTablePrefixFromConfig accepts mixed case prefixes', function () {
    $content = "<?php\n\$table_prefix = 'JZ8I7oGy_';";
    expect(\App\Livewire\Project\Service\WordPressManager::parseTablePrefixFromConfig($content))
        ->toBe('JZ8I7oGy_');
});

it('parseTablePrefixFromConfig returns null on invalid prefix characters', function () {
    $content = "<?php\n\$table_prefix = 'invalid!';";
    expect(\App\Livewire\Project\Service\WordPressManager::parseTablePrefixFromConfig($content))
        ->toBeNull();
});

/* -----------------------------------------------------------------
 | FileExplorer: land on /var/www/html for WordPress services
 | ----------------------------------------------------------------- */

it('FileExplorer lands on /var/www/html for WordPress services, not just Laravel Rootkit', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/FileExplorer.php');

    expect($source)
        ->toContain('shouldDefaultToAppWorkdirPath')
        // WordPress marker detection in the compose raw.
        ->toContain('WORDPRESS_DB_HOST')
        ->toContain('WORDPRESS_DB_NAME')
        // Check for wp-config.php / wp-content as WordPress markers.
        ->toContain('wp-config.php')
        ->toContain('wp-content');
});
