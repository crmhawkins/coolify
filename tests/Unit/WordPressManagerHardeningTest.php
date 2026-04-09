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

it('syncUrls SQL fallback covers the canonical URL tables', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    expect($source)
        ->toContain('private function isWpCliAvailable')
        ->toContain('private function runSyncUrlsWithWpCli')
        ->toContain('private function runSyncUrlsWithSql')
        ->toContain('WORDPRESS_DB_HOST')
        ->toContain('WORDPRESS_DB_PASSWORD')
        ->toContain('WORDPRESS_DB_NAME')
        ->toContain('WORDPRESS_DB_USER')
        ->toContain('MYSQL_PWD=')
        ->toContain('datos serializados');
});

/* -----------------------------------------------------------------
 | updateWpPrefix: atomic RENAME TABLE + user_meta fix
 | ----------------------------------------------------------------- */

it('updateWpPrefix refuses to run without mysql CLI to avoid tumbling the site', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    expect($source)
        // Abort early if mysql is not present.
        ->toContain('No puedo renombrar las tablas')
        ->toContain("`command -v mysql");
});

it('updateWpPrefix builds a single atomic RENAME TABLE + user_meta + options update', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    expect($source)
        ->toContain('RENAME TABLE ')
        ->toContain("SHOW TABLES LIKE '")
        ->toContain('usermeta` SET meta_key = REPLACE')
        ->toContain('options` SET option_name = REPLACE')
        // Backs up wp-config.php before touching it.
        ->toContain('wp-config.php.backup-')
        // Rejects prefixes without trailing underscore (WP convention).
        ->toContain('debe terminar en un guion bajo');
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
