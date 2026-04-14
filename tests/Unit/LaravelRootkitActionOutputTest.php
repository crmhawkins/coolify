<?php

it('reports deployed commits and focused failure stages in rootkit stack actions', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        ->toContain('New commits deployed:')
        ->toContain('No new commits to deploy.')
        ->toContain('Failed at: composer install')
        ->toContain('Failed at: npm install/build')
        ->toContain('Migration status before run:')
        ->toContain('Migrations completed successfully with no warnings.')
        ->toContain('Failed at: php artisan migrate --force')
        ->toContain('runLaravelMaintenanceCommand')
        // The stack heading now exposes a single unified "Clear Cache All"
        // action instead of the previous config-cache / queue-restart /
        // queue-work-once buttons. The single entry still runs the same
        // php artisan config:clear + cache:clear combo plus route/view/event
        // cleanup inside the container.
        ->toContain("'clear-all'")
        ->toContain('php artisan config:clear')
        ->toContain('php artisan cache:clear')
        ->toContain('php artisan route:clear')
        ->toContain('php artisan view:clear');
});

it('hardens deployLaravelChanges against dubious ownership, broken .git and missing vendor', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        // Git must trust the working tree even when root runs inside a
        // www-data-owned checkout. Without this the first git op aborts
        // with "detected dubious ownership" and Deploy cambios fails with
        // zero context.
        ->toContain('git config --global --add safe.directory /var/www/html')
        // If .git is missing or structurally broken we must give the user
        // an actionable next step (Redeploy from Coolify) instead of a
        // bare "git fetch failed".
        ->toContain('Repository is not initialized in /var/www/html')
        ->toContain('.git directory is corrupted')
        // The fetch must write stdout+stderr to a log file so the real
        // git error surfaces in the Asset Command Output panel. The old
        // behaviour used --quiet which swallowed the actual message.
        ->toContain('GIT_FETCH_LOG=/tmp/coolify-git-fetch.log')
        ->toContain('Failed at: git fetch origin')
        // Deploy cambios must log the "installing from scratch" case so
        // operators can tell the difference between a normal incremental
        // deploy and a recovery deploy that had to rebuild vendor/.
        ->toContain('vendor/autoload.php missing — installing PHP dependencies from scratch.')
        ->toContain('Composer dependencies installed.');
});

it('auto-recovers missing vendor autoload in run migrations and clear cache all', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        // Both buttons share a single helper that runs composer install
        // when vendor/autoload.php is missing, so operators no longer get
        // the raw "Failed to open stream: vendor/autoload.php" PHP fatal
        // when they click Run migrations or Clear Cache All on a half
        // deployed laravel-rootkit stack.
        ->toContain('buildEnsureComposerInstalledFragment')
        ->toContain('vendor/autoload.php missing — installing PHP dependencies automatically before continuing.')
        ->toContain('Failed at: composer install (auto-recovery)')
        // Safety net: if composer.json is not present either, the helper
        // must refuse to run composer install and tell the user to redeploy
        // so the entrypoint can re-clone the repo.
        ->toContain('vendor/autoload.php is missing and composer.json was not found');
});

it('configures laravel rootkit to use file cache and guarded schedule run mode', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');

    expect($template)
        ->toContain('php artisan schedule:run --no-interaction')
        ->toContain('SERVICE_LARAVEL_SCHEDULER_ENABLED')
        ->toContain('SERVICE_LARAVEL_QUEUE_NAMES')
        ->toContain('php artisan schedule:list --no-interaction')
        ->toContain('queue:work --queue=${SERVICE_LARAVEL_QUEUE_NAMES:-default}')
        ->toContain('numprocs=6')
        ->toContain('php -m | grep -q "^exif$"')
        ->toContain('pdo_mysql zip bcmath gd intl exif mbstring opcache')
        ->toContain('condition: service_healthy')
        ->toContain('No such container')
        ->toContain('command: ["nginx", "-g", "daemon off;"]')
        ->toContain('try_files /_coolify_preflight_error.html $uri $uri/ /index.php?$query_string;')
        ->toContain('fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;')
        ->toContain('fastcgi_param HTTP_X_FORWARDED_HOST $http_x_forwarded_host;')
        ->toContain('fastcgi_param HTTP_X_FORWARDED_PORT $http_x_forwarded_port;')
        // HTTPS fastcgi_param must derive from a safe map (on/off), not
        // directly from $http_x_forwarded_proto which would evaluate truthy
        // for plain HTTP requests in Symfony::isSecure().
        ->toContain('map $http_x_forwarded_proto $fastcgi_https {')
        ->toContain('fastcgi_param HTTPS $fastcgi_https;')
        ->not->toContain('fastcgi_param HTTPS $http_x_forwarded_proto;')
        ->toContain('fastcgi_param REQUEST_SCHEME $http_x_forwarded_proto;')
        ->toContain('CACHE_STORE=file')
        ->toContain('upsert_env "CACHE_STORE" "file"')
        ->toContain('upsert_env "SESSION_DRIVER" "file"')
        ->toContain('if [ -z "${CURRENT_APP_KEY}" ]; then')
        ->not->toContain('upsert_env "APP_KEY" ""')
        // Security and robustness fixes applied to the entrypoint.
        ->toContain('REPO_URL_PUBLIC')
        ->toContain('git remote set-url origin "${REPO_URL_PUBLIC}"')
        ->toContain('git clone --depth 1 --no-tags')
        ->toContain('DEFAULT_BRANCH="$(git symbolic-ref')
        // Scheduler and queue workers must run as the php-fpm user so cache
        // and session files are not created as root.
        ->toContain('user=www-data')
        // Chown is scoped: full tree chown is removed in favour of writable
        // directories only, which keeps restarts fast on large codebases.
        ->toContain('chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache')
        ->not->toContain('chown -R www-data:www-data /var/www/html'."\n")
        // APP_DEBUG is configurable via Coolify env var.
        ->toContain('APP_DEBUG=${SERVICE_LARAVEL_APP_DEBUG:-false}');
});

it('hardens laravel rootkit boot pipeline so broken deploys surface as unhealthy', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');

    expect($template)
        // Fix 1 — php-fpm healthcheck now fails when vendor/autoload.php
        // is missing or the install-failed sentinel is present. The old
        // check only looked at artisan, which meant Coolify reported a
        // broken deploy as "running/healthy" while users saw PHP fatals.
        ->toContain('test -f /var/www/html/vendor/autoload.php')
        ->toContain('test ! -f /var/www/html/.coolify_install_failed')
        // Fix 2 — composer install fast-path keyed on composer.lock hash.
        // Cuts boot time on unchanged deploys from ~10-30s to near zero
        // and survives across container recreation because the hash file
        // lives inside the laravel-files named volume.
        ->toContain('/var/www/html/.coolify/composer.lock.sha256')
        ->toContain('composer.lock unchanged and vendor/autoload.php present — skipping composer install.')
        // Fix 3 — sentinel file is both created on composer install
        // failure and cleared on every successful / skipped install, so
        // the health state flips back to green once the user fixes the
        // underlying problem and redeploys.
        ->toContain(': > /var/www/html/.coolify_install_failed')
        ->toContain('rm -f /var/www/html/.coolify_install_failed')
        // Fix 4 — nginx serves the Spanish error page as error_page
        // fallback when PHP 500s (missing vendor, php-fpm down, etc).
        ->toContain('error_page 500 502 503 504 /_coolify_error.html;')
        ->toContain('location = /_coolify_error.html {')
        // Fix 5 — first-time git clone is a hard fail with an actionable
        // message pointing at the SERVICE_GITHUB_* env vars instead of a
        // raw git stderr buried in container logs.
        ->toContain('FATAL: git clone failed')
        ->toContain('Check SERVICE_GITHUB_REPO_URL, SERVICE_GITHUB_BRANCH, SERVICE_GITHUB_TOKEN')
        // Fix 6 — scheduler and queue-worker block on both artisan AND
        // vendor/autoload.php before starting, so they stop crash-looping
        // while the entrypoint is still running composer install.
        ->toContain('Waiting for Laravel installation (artisan + vendor/autoload.php)...')
        ->toContain('[ ! -f /var/www/html/artisan ] || [ ! -f /var/www/html/vendor/autoload.php ]');
});

it('loads cron tasks from artisan schedule list or project source fallback', function () {
    $cronFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/LaravelCron.php');
    $cronView = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/laravel-cron.blade.php');

    expect($cronFile)
        ->toContain('php artisan schedule:list --format=json --no-interaction')
        ->toContain('php artisan schedule:list --json --no-interaction')
        ->toContain('parseScheduleSourceOutput')
        ->toContain('Showing schedule definitions detected in project source')
        ->toContain('sanitizeScheduleCommandOutput')
        // Modern Laravel plain-text schedule:list parser lives in the same
        // method as the JSON / Symfony-table parsers. Lock the regex
        // signature so future refactors do not silently drop it.
        ->toContain('Next Due:');

    expect($cronView)
        // The card-based redesign uses a scheduler status badge and a
        // responsive grid. Lock these identifiers so future refactors
        // cannot silently strip them out.
        ->toContain('Scheduler activo')
        ->toContain('Scheduler detenido')
        ->toContain('md:grid-cols-2')
        ->toContain('Próxima')
        ->toContain('executeTaskNow');
});
