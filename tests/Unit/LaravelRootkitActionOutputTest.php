<?php

it('reports deployed commits and focused failure stages in rootkit stack actions', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        ->toContain('New commits deployed:')
        ->toContain('No new commits to deploy.')
        ->toContain('Failed at: composer install')
        ->toContain('Failed at: npm install/build')
        ->toContain('Migration status before run:')
        ->toContain('Migration status after run:')
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

it('run migrations handles package discovery, extension check, and common failure hints', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        // package:discover regenerates bootstrap/cache/packages.php so
        // migrations registered via ServiceProvider::loadMigrationsFrom()
        // (typical of spatie, barryvdh, squareetlabs packages) are
        // visible to the migrate command even if composer's post-script
        // was skipped by the composer.lock hash cache shortcut.
        ->toContain('php artisan package:discover --no-ansi')
        // The extension installer runs before migrate so a migration
        // that uses an extension not yet loaded (e.g. a migration that
        // uses mb_convert_encoding or dom helpers) does not crash with
        // "call to undefined function". Reuses the same helper as
        // Deploy cambios so migrate and deploy converge on extension
        // state over time.
        ->toContain('buildEnsureExtensionsInstalledFragment')
        // Output limit lifted from 500 to 2000 lines so first-time
        // migrations on CRMs with 100+ migrations do not get truncated.
        ->toContain("sed -n '1,2000p'")
        // Actionable hints for the six failure modes we actually hit
        // in production on this fork:
        //   1. action_scheduler_logs / dumped PK without AUTO_INCREMENT
        //   2. unknown database (DB_DATABASE mismatch)
        //   3. connection refused (mariadb not ready / wrong DB_HOST)
        //   4. SQLSTATE[42S01] table already exists (restored dump,
        //      migrations table out of sync with schema)
        //   5. SQLSTATE[42S21] / Duplicate column name (same family
        //      but at column level)
        //   6. SQLSTATE[23000] foreign key constraint failure
        ->toContain("a PRIMARY KEY column lost its AUTO_INCREMENT attribute")
        ->toContain('the database does not exist')
        ->toContain('cannot connect to the database')
        ->toContain('a migration tried to create a table that already exists')
        ->toContain('a migration tried to ADD a column that already exists')
        ->toContain('a foreign key constraint failed');
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

it('deploy cambios auto-installs missing ext-* from composer.lock before composer install', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        // Helper exists and is wired into deployLaravelChanges
        ->toContain('buildEnsureExtensionsInstalledFragment')
        ->toContain('Deploy cambios: installing missing PHP extensions from composer.lock:')
        // The parser reads composer.lock (platform + every package's
        // require map), not just composer.json, so transitive ext-*
        // requirements (the polako → laravel-verifactu → ext-soap case)
        // are picked up too.
        ->toContain('composer.lock')
        ->toContain('"platform"')
        ->toContain('"platform-dev"')
        ->toContain('"packages-dev"')
        // Recipe coverage: the extensions that blocked real deploys
        // (soap, imap) and the popular ones that need apk dev packages
        // must all have a dedicated case so they don't fall through
        // to the generic docker-php-ext-install that misses their
        // dependencies (libxml2-dev for soap, imap-dev for imap, etc).
        ->toContain('apk add --no-cache libxml2-dev')
        ->toContain('docker-php-ext-install -j"$(nproc)" soap')
        ->toContain('apk add --no-cache imap-dev krb5-dev openssl-dev')
        ->toContain('docker-php-ext-configure imap --with-imap --with-imap-ssl')
        ->toContain('apk add --no-cache openldap-dev')
        ->toContain('apk add --no-cache postgresql-dev')
        // redis, xdebug and apcu share a single pecl branch — they are
        // pure pecl packages with no extra apk dependencies on Alpine.
        ->toContain('xdebug|apcu|redis')
        // Hard fail + actionable hint pointing at Redeploy when the
        // installer can't resolve an extension. This prevents composer
        // install from running against a broken PHP and producing
        // misleading errors.
        ->toContain('Could not install required PHP extensions:')
        ->toContain('Redeploy the service from Coolify (recreate container) for a full reinstall')
        // PHP-FPM is reloaded via USR2 at the end so running workers
        // pick up the newly installed extensions without a full
        // container restart.
        ->toContain("pkill -USR2 -f 'php-fpm: master'");
});

it('deploy cambios keeps working on public repos whether or not a GitHub token is set', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        ->toContain("if (\$githubToken !== '')")
        // GitHub's git HTTPS smart transport reliably accepts Basic
        // auth with base64(x-access-token:<pat>), matching the
        // scheme the template entrypoint uses. The older Bearer
        // form would get silently rejected on some git versions and
        // trigger the "could not read Username" prompt even for
        // public repos where the token was unnecessary.
        ->toContain("'Authorization: Basic '.base64_encode('x-access-token:'.\$githubToken)")
        ->toContain('-c http.extraHeader=')
        ->not->toContain("'Authorization: Bearer '.\$githubToken")
        // The ordering must be: fetch-config fragment is built BEFORE
        // the command string, then spliced via {$fetchConfigFragment}
        // inside the single fetch call. No other shell command should
        // leak the raw token into .git/config.
        ->toContain('git remote set-url origin')
        ->not->toContain('git remote set-url origin https://x-access-token');
});

it('deploy cambios retries anonymously when the token fetch fails on a public repo', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        // The two-attempt fetch block is only emitted when a token is
        // present at build time — without a token we skip straight to
        // the anonymous fetch. Lock both branches so refactors cannot
        // silently drop the fallback.
        ->toContain('retrying anonymously (works for public repos)')
        ->toContain('anonymous fetch succeeded — token was unnecessary or invalid (repo is public).')
        // On total failure the HINT should distinguish "token wrong"
        // from "token missing": we already tried anonymous, so the
        // user knows it's not a "set the token" issue but a token
        // validity/scope issue OR the repo actually is private.
        ->toContain('The repository is private and your SERVICE_GITHUB_TOKEN is either missing, expired, or lacks Contents:read scope on this repo.')
        // The no-token branch keeps the original simpler HINT.
        ->toContain('HINT: the repository looks private. Set SERVICE_GITHUB_TOKEN in the Coolify service environment and try again.');
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
        // First-boot init uses git init + fetch + checkout (NOT
        // git clone .) because /var/www/html has a storage
        // mountpoint that makes the destination non-empty and
        // `git clone` refuses. See the matching it() block below
        // for the full rationale.
        ->toContain('git init -q .')
        ->toContain('git fetch --depth 1 --no-tags origin')
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

it('installs ext-imap on demand and picks up ext requirements from composer.lock', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');

    expect($template)
        // The dynamic extension installer must have an explicit recipe
        // for ext-imap (Alpine apk prerequisites + docker-php-ext on
        // PHP <= 8.3 with a pecl fallback for PHP 8.4 where the
        // extension was removed from php-src). Without the recipe the
        // generic `*)` branch tries docker-php-ext-install imap and
        // fails because imap-dev / krb5-dev are not on the image.
        ->toContain('imap-dev krb5-dev openssl-dev')
        ->toContain('docker-php-ext-configure imap --with-imap --with-imap-ssl')
        ->toContain('pecl install imap')
        // composer install reads composer.lock, not composer.json, so
        // the pre-flight extension installer has to match that view of
        // the world. The parser now merges platform/platform-dev plus
        // every package's require list from the lock, on top of the
        // project composer.json require / require-dev.
        ->toContain('$l = @json_decode(@file_get_contents("composer.lock"), true);')
        ->toContain('"platform"')
        ->toContain('"platform-dev"')
        ->toContain('"packages-dev"');
});

it('guards git operations against dubious ownership and interactive prompts', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');
    $stackForm = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($template)
        // safe.directory and GIT_TERMINAL_PROMPT must be configured
        // BEFORE the first git op. The old placement left both after
        // the fetch/checkout block, so the very first subsequent boot
        // on a volume owned by a different uid aborted with
        // "fatal: detected dubious ownership" before we ever applied
        // the fix. Lock the ordering by asserting both keywords land
        // ahead of the `if [ ! -d .git ]` clone branch.
        ->toContain('export GIT_TERMINAL_PROMPT=0')
        ->toContain('git config --global --add safe.directory /var/www/html');

    // Structural assertion: safe.directory must sit before the clone.
    $safePos = strpos($template, 'git config --global --add safe.directory /var/www/html');
    $clonePos = strpos($template, 'if [ ! -d .git ]; then');
    expect($safePos)->toBeLessThan($clonePos);
    // And it must no longer appear AFTER the fetch/checkout block at
    // the old position (the legacy duplicate line has been removed).
    $fetchPos = strpos($template, 'git fetch --prune origin 2>&1');
    expect($fetchPos)->toBeGreaterThan(0);
    // If a second safe.directory directive still existed after the
    // fetch block it would mean the cleanup did not land.
    expect(substr_count($template, 'git config --global --add safe.directory /var/www/html'))->toBe(1);

    expect($stackForm)
        // Deploy cambios must also export GIT_TERMINAL_PROMPT=0 before
        // the fetch so a missing/expired token fails fast instead of
        // hanging the docker exec session on an invisible prompt.
        ->toContain('export GIT_TERMINAL_PROMPT=0')
        // When fetch fails with the "could not read Username"
        // signature we surface an explicit hint pointing at
        // SERVICE_GITHUB_TOKEN so operators know exactly what to fix.
        ->toContain("grep -q 'could not read Username'")
        ->toContain('HINT: the repository looks private and no valid SERVICE_GITHUB_TOKEN is configured.');
});

it('boots new services without crashlooping on the storage mountpoint (git init + fetch, never git clone .)', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');

    // Since f37c8616f the entrypoint can no longer run `git clone .`
    // on first boot: /var/www/html has a sub-mountpoint at
    // /var/www/html/storage (the dedicated laravel-storage volume)
    // that `rm -rf` cannot remove, so `git clone .` always saw a
    // non-empty destination and failed with "destination path '.'
    // already exists and is not an empty directory", putting every
    // brand-new service in a crashloop. Fix is git init + fetch +
    // checkout which tolerates a non-empty directory.
    expect($template)
        // Hard-not: the old `git clone . ...` pattern must never come
        // back — if it does, any new service with the storage split
        // template crashlops out of the gate.
        ->not->toContain('git clone --depth 1 --no-tags --branch "${REPO_BRANCH}" --single-branch "${REPO_URL}" .')
        ->toContain('git init -q .')
        ->toContain('git fetch --depth 1 --no-tags origin "${REPO_BRANCH}"')
        ->toContain('git checkout -f -B "${REPO_BRANCH}" "origin/${REPO_BRANCH}"')
        // The pre-init cleanup must EXCLUDE storage so the mount
        // stays intact (otherwise the next `rm -rf` try tries to
        // tear down the mountpoint and leaves the tree in limbo).
        ->toContain("! -name 'storage'");
});

it('isolates /var/www/html/storage in its own named volume so uploads survive any redeploy', function () {
    $template = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');

    expect($template)
        // Named volume declaration at the compose top level.
        ->toContain("\nvolumes:\n  laravel-files:\n")
        ->toContain('laravel-storage:')
        // Laravel service mounts both laravel-files (app code) AND
        // laravel-storage (user uploads / PDFs / logs). The second
        // mount shadows the storage subdir of laravel-files, which
        // is exactly what we want: any git-clone wipe of
        // laravel-files leaves laravel-storage untouched.
        ->toContain('- laravel-files:/var/www/html')
        ->toContain('- laravel-storage:/var/www/html/storage')
        // Nginx must see storage too (read-only) so public/storage
        // symlink requests resolve to real files instead of 404.
        ->toContain('- laravel-files:/var/www/html:ro')
        ->toContain('- laravel-storage:/var/www/html/storage:ro')
        // storage:link runs with --force so a stale symlink pointing
        // at a removed inode (after laravel-files recreation) is
        // rebuilt in place.
        ->toContain('php artisan storage:link --force 2>/dev/null || true');
});

it('propagates APP_NAME changes to the live Laravel .env on Save (no redeploy needed)', function () {
    $stackForm = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackForm)
        // Helper exists and is wired into submit()
        ->toContain('propagateAppNameToLaravelEnv')
        // submit() must snapshot the old value BEFORE sync/save so the
        // change-detection diff is accurate (reading from $this->fields
        // after save would always look unchanged).
        ->toContain("->where('key', 'SERVICE_LARAVEL_APP_NAME')")
        // Success toast advertises that Laravel picks up the new value
        // without Redeploy — that's the whole point of this helper.
        ->toContain("APP_NAME actualizado en el .env del contenedor live. Laravel lee el nuevo valor en el siguiente request sin Redeploy.")
        // Implementation uses docker exec -e COOLIFY_APP_NAME_NEW=...
        // + a php -r one-liner inside the container, not sed. The sed
        // approach breaks on values with `/` or `&` (legitimate
        // characters in an app name like "Hawkins/CRM & Partners").
        ->toContain('COOLIFY_APP_NAME_NEW=')
        ->toContain('getenv("COOLIFY_APP_NAME_NEW")')
        // After editing .env the helper clears + caches config so the
        // next request picks up the new value (Laravel does not re-read
        // .env on every request when config:cache has run).
        ->toContain('php artisan config:clear')
        ->toContain('php artisan config:cache')
        // UPDATED / UNCHANGED sentinels gate the success dispatch — if
        // neither came back, the helper returns false and the caller
        // silently skips the toast.
        ->toContain('UPDATED')
        ->toContain('UNCHANGED');
});

it('backfills SERVICE_LARAVEL_APP_NAME in mount() for services missing the env var', function () {
    $stackForm = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    // Services created with the old template (pre-489a42864) or
    // while service-templates-latest.json was still cached on the
    // old version do NOT have a SERVICE_LARAVEL_APP_NAME row in
    // environment_variables. Without a backfill the blade guard
    // $fields->has(...) evaluates to false and the input never
    // renders. Same recovery pattern the mount() already uses for
    // SERVICE_GITHUB_TOKEN and SERVICE_GITHUB_BRANCH: synthesize
    // the field in $fields with the default value and let
    // saveExtraFields() persist it on first Save.
    expect($stackForm)
        ->toContain("\$this->isLaravelRootkitStack() && ! \$this->fields->has('SERVICE_LARAVEL_APP_NAME')")
        ->toContain("->where('key', 'SERVICE_LARAVEL_APP_NAME')")
        ->toContain("'value' => data_get(\$appName, 'value', 'Laravel RootKit')")
        ->toContain("'name' => 'APP_NAME (Laravel)'")
        ->toContain("'rules' => 'nullable|string|max:120'");
});

it('exposes SERVICE_LARAVEL_APP_NAME so operators can edit APP_NAME inline in the stack form', function () {
    $template  = file_get_contents(__DIR__.'/../../templates/compose/laravel-rootkit.yaml');
    $blade     = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/stack-form.blade.php');

    // The compose env block references the field via the SERVICE_*
    // convention, falling back to the original hardcoded default so
    // legacy services keep the same label after the upgrade.
    expect($template)
        ->toContain('APP_NAME=${SERVICE_LARAVEL_APP_NAME:-Laravel RootKit}')
        // The entrypoint must upsert APP_NAME into the project .env so
        // Laravel actually uses the new label — just passing the env
        // var to the process is not enough because artisan config:cache
        // reads .env at build time.
        ->toContain('if [ -n "${APP_NAME:-}" ]; then')
        ->toContain('upsert_env "APP_NAME" "${APP_NAME}"');

    // The blade form renders the input only for Laravel Rootkit stacks
    // where the template actually exposed the field, and it sits right
    // after Service Name / Description for discoverability.
    expect($blade)
        ->toContain("\$this->isLaravelRootkitStack() && \$fields->has('SERVICE_LARAVEL_APP_NAME')")
        ->toContain('id="fields.SERVICE_LARAVEL_APP_NAME.value"')
        ->toContain('APP_NAME (Laravel)');
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
        // Fix 5 — first-time repo init is a hard fail with an
        // actionable message pointing at the SERVICE_GITHUB_* env vars
        // instead of a raw git stderr buried in container logs.
        ->toContain('FATAL: git fetch failed')
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
