<?php

use App\Livewire\Project\Service\LaravelManager;

/*
|--------------------------------------------------------------------------
| Laravel RootKit hardening regression tests
|--------------------------------------------------------------------------
|
| Pest-3 style unit tests covering the pure-logic helpers introduced or
| touched during the Laravel RootKit hardening pass. Kept deliberately
| free of Livewire / SSH / container plumbing so the whole file runs in
| ~milliseconds and can be invoked as
|
|     php artisan test --compact --filter=LaravelRootKitHardening
|
| Each test pins a specific invariant we do not want to regress by
| accident: IDOR scoping on the 4 Livewire mount() calls, the php.ini
| validator bounds, the GitHub-token-in-.git/config fix, the
| .env / php.ini dirty-tracking wrappers, and the Artisan run
| confirmation dialog.
|
*/

/* -----------------------------------------------------------------
 | L8 — IDOR: every Laravel Livewire component must scope its
 | Service::whereUuid() lookup through ownedByCurrentTeam() at mount
 | time so users cannot reach the Manager/Artisan/Cron/GitSource UI
 | for a service that belongs to a different team.
 | ----------------------------------------------------------------- */

dataset('rootkitLivewireComponents', [
    'LaravelManager' => 'app/Livewire/Project/Service/LaravelManager.php',
    'LaravelArtisan' => 'app/Livewire/Project/Service/LaravelArtisan.php',
    'LaravelCron' => 'app/Livewire/Project/Service/LaravelCron.php',
    'LaravelGitSource' => 'app/Livewire/Project/Service/LaravelGitSource.php',
]);

it('scopes Service::whereUuid to the current team in mount()', function (string $relativePath) {
    $source = file_get_contents(__DIR__.'/../../'.($relativePath));

    expect($source)
        ->toContain('Service::ownedByCurrentTeam()')
        // And must NOT contain the old unscoped bare lookup that
        // loaded any service by UUID regardless of ownership.
        ->not->toMatch('/Service::whereUuid\(request\(\)->route/');
})->with('rootkitLivewireComponents');

/* -----------------------------------------------------------------
 | L7 — php.ini per-directive validator. Pure static helpers with no
 | side effects, so we exercise them directly.
 | ----------------------------------------------------------------- */

it('parses byte-sized php.ini values with K/M/G suffixes', function () {
    expect(LaravelManager::parsePhpIniValue('512M', 'bytes'))->toBe(512 * 1024 * 1024);
    expect(LaravelManager::parsePhpIniValue('4096K', 'bytes'))->toBe(4096 * 1024);
    expect(LaravelManager::parsePhpIniValue('2G', 'bytes'))->toBe(2 * 1024 * 1024 * 1024);
    expect(LaravelManager::parsePhpIniValue('100', 'bytes'))->toBe(100);
    expect(LaravelManager::parsePhpIniValue('-1', 'bytes'))->toBe(-1);
});

it('rejects K/M/G suffixes on non-byte directives (count, seconds)', function () {
    // opcache.memory_consumption is a count, not bytes — "128M" is a
    // footgun that silently becomes huge if we lean on the suffix.
    expect(LaravelManager::parsePhpIniValue('128M', 'count'))->toBeNull();
    expect(LaravelManager::parsePhpIniValue('600M', 'seconds'))->toBeNull();
    expect(LaravelManager::parsePhpIniValue('128', 'count'))->toBe(128);
    expect(LaravelManager::parsePhpIniValue('600', 'seconds'))->toBe(600);
});

it('rejects syntactically malformed php.ini values', function () {
    expect(LaravelManager::parsePhpIniValue(''))->toBeNull();
    expect(LaravelManager::parsePhpIniValue('abc'))->toBeNull();
    expect(LaravelManager::parsePhpIniValue('512MB'))->toBeNull();
    expect(LaravelManager::parsePhpIniValue('5.5M'))->toBeNull();
    expect(LaravelManager::parsePhpIniValue('+100'))->toBeNull();
});

it('accepts every PHP_INI_DEFAULTS_LARAVEL value against its bound', function () {
    foreach (LaravelManager::PHP_INI_DEFAULTS_LARAVEL as $key => $value) {
        $error = LaravelManager::validatePhpIniValue($key, $value);
        expect($error)->toBeNull(
            "Laravel default {$key}={$value} should pass validation but got: {$error}"
        );
    }
});

it('accepts every PHP_INI_DEFAULTS_PHPMYADMIN value against its bound', function () {
    foreach (LaravelManager::PHP_INI_DEFAULTS_PHPMYADMIN as $key => $value) {
        $error = LaravelManager::validatePhpIniValue($key, $value);
        expect($error)->toBeNull(
            "phpMyAdmin default {$key}={$value} should pass validation but got: {$error}"
        );
    }
});

it('rejects memory_limit above 8G (OOM footgun)', function () {
    $error = LaravelManager::validatePhpIniValue('memory_limit', '999999G');
    expect($error)->not->toBeNull();
    expect($error)->toContain('memory_limit');
    expect($error)->toContain('demasiado alto');
});

it('rejects memory_limit below 64M', function () {
    $error = LaravelManager::validatePhpIniValue('memory_limit', '16M');
    expect($error)->not->toBeNull();
    expect($error)->toContain('demasiado bajo');
});

it('accepts memory_limit = -1 (explicitly whitelisted for Laravel)', function () {
    expect(LaravelManager::validatePhpIniValue('memory_limit', '-1'))->toBeNull();
});

it('rejects -1 on directives that do not whitelist it', function () {
    $error = LaravelManager::validatePhpIniValue('max_input_vars', '-1');
    expect($error)->not->toBeNull();
    expect($error)->toContain('no está permitido');
});

it('rejects unknown php.ini directives via the whitelist', function () {
    $error = LaravelManager::validatePhpIniValue('allow_url_include', 'On');
    expect($error)->not->toBeNull();
    expect($error)->toContain('no está permitida');
});

it('formats byte bounds back to human-readable K/M/G suffixes', function () {
    expect(LaravelManager::formatPhpIniBound(512 * 1024 * 1024, 'bytes'))->toBe('512M');
    expect(LaravelManager::formatPhpIniBound(4096 * 1024, 'bytes'))->toBe('4096K');
    expect(LaravelManager::formatPhpIniBound(2 * 1024 * 1024 * 1024, 'bytes'))->toBe('2G');
    expect(LaravelManager::formatPhpIniBound(600, 'seconds'))->toBe('600s');
    expect(LaravelManager::formatPhpIniBound(100, 'count'))->toBe('100');
});

/* -----------------------------------------------------------------
 | L3 — GitHub token must never be written into .git/config on disk.
 | We assert on the source text rather than reaching into StackForm
 | because the surrounding method has SSH side effects we would have
 | to mock at huge cost.
 | ----------------------------------------------------------------- */

it('deploys with a clean (tokenless) origin URL and injects token via http.extraHeader', function () {
    $source = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/StackForm.php'));

    expect($source)
        // The new helper that strips the token exists and is called.
        ->toContain('buildCleanGithubHttpsUrl')
        ->toContain('$cleanRepoUrl = $this->buildCleanGithubHttpsUrl($repoUrl)')
        // Token is passed out-of-band via git -c http.extraHeader.
        ->toContain('http.extraHeader=')
        ->toContain('Authorization: Bearer ')
        // Bulk set-url now uses the CLEAN url, not the token-embedded one.
        ->toContain("git remote set-url origin \".escapeshellarg(\$cleanRepoUrl)")
        // And we also reset the push remote so both directions are
        // tokenless.
        ->toContain("git remote set-url --push origin \".escapeshellarg(\$cleanRepoUrl)")
        // The old vulnerable line is gone.
        ->not->toContain("git remote set-url origin \".escapeshellarg(\$deployRepoUrl)");
});

/* -----------------------------------------------------------------
 | L2 — Artisan run button: direct execution without confirm dialog.
 | The pre-execution confirm was removed at operator request because
 | the panel is already gated by the service's update capability and
 | the extra dialog was friction for power users running migrate /
 | optimize:clear / cache:clear repeatedly. This test locks in the
 | "no confirmation" behaviour so a future refactor does not
 | re-introduce the dialog.
 | ----------------------------------------------------------------- */

it('does not guard the LaravelArtisan run button with a confirm dialog', function () {
    $blade = file_get_contents(__DIR__.'/../../'.('resources/views/livewire/project/service/laravel-artisan.blade.php'));

    // The old Alpine wrapper is gone: Enter on the input dispatches
    // $wire.run() directly, which is the same method the button
    // click-binding triggers.
    expect($blade)->not->toContain('confirmAndRun()');
    expect($blade)->toContain('x-on:keydown.enter.prevent="$wire.run()"');

    // The Ejecutar button no longer ships wire:confirm — a click runs
    // the command straight away.
    expect($blade)->not->toContain('wire:confirm="¿Ejecutar el comando artisan');

    // Defensive: the original bare wire:keydown.enter.prevent="run"
    // should never have shipped and must stay gone (Livewire swallows
    // the keystroke on `wire:keydown.enter.prevent="run"` with debounced
    // wire:model inputs, which was the original reason we moved the
    // Enter handling to x-on:keydown).
    expect($blade)->not->toContain('wire:keydown.enter.prevent="run"');
});

/* -----------------------------------------------------------------
 | L1 — dirty tracking on the .env and php.ini editors. The Alpine
 | wrappers must exist and listen for the matching Livewire events
 | dispatched from the PHP side after a save/reload.
 | ----------------------------------------------------------------- */

it('wraps the .env editor with a beforeunload dirty guard', function () {
    $blade = file_get_contents(__DIR__.'/../../'.('resources/views/livewire/project/service/laravel-manager.blade.php'));

    expect($blade)
        ->toContain("x-on:env-reloaded.window=\"markClean()\"")
        ->toContain("x-on:env-saved.window=\"markClean()\"")
        ->toContain("window.addEventListener('beforeunload', this.beforeUnloadHandler)");

    $php = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/LaravelManager.php'));
    expect($php)
        ->toContain("\$this->dispatch('env-reloaded')")
        ->toContain("\$this->dispatch('env-saved')");
});

it('wraps the php.ini editor with a beforeunload dirty guard', function () {
    $blade = file_get_contents(__DIR__.'/../../'.('resources/views/livewire/project/service/laravel-manager.blade.php'));

    expect($blade)
        ->toContain("x-on:phpini-reloaded.window=\"markClean()\"")
        ->toContain("x-on:phpini-saved.window=\"markClean()\"")
        ->toContain('data-ini-key=');

    $php = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/LaravelManager.php'));
    expect($php)
        ->toContain("\$this->dispatch('phpini-reloaded')")
        ->toContain("\$this->dispatch('phpini-saved')");
});

/* -----------------------------------------------------------------
 | L4 — remote tmp cleanup is wrapped in a logged try/catch so a
 | broken SSH session on cleanup no longer leaves silent /tmp leaks.
 | ----------------------------------------------------------------- */

it('routes remote tmp cleanups through safeRemoteCleanup()', function () {
    $php = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/LaravelManager.php'));

    expect($php)
        ->toContain('private function safeRemoteCleanup')
        ->toContain('\\Log::warning(\'LaravelManager: remote tmp cleanup failed\'')
        // Both save paths now go through the helper.
        ->toContain("\$this->safeRemoteCleanup(\$cleanCommand, \$server, 'laravel-env tmp file')")
        ->toContain("\$this->safeRemoteCleanup(\$cleanCommand, \$server, 'laravel-php-ini tmp file')");
});

/* -----------------------------------------------------------------
 | L5 — strict container detection in LaravelManager
 | ----------------------------------------------------------------- */

it('blacklists database containers from the Laravel Manager UI', function () {
    $php = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/LaravelManager.php'));

    expect($php)
        ->toContain("'mariadb'")
        ->toContain("'postgres'")
        ->toContain("'redis'")
        ->toContain("'mongodb'")
        ->toContain('elasticsearch');
});

/* -----------------------------------------------------------------
 | L6 — schedule:list sanitiser drops PHP runtime notices and strips
 | any banner before the first JSON brace.
 | ----------------------------------------------------------------- */

it('hardens the schedule:list output sanitiser', function () {
    $php = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/LaravelCron.php'));

    expect($php)
        // The new regex-based noise filter.
        ->toContain('noisePrefixRegex')
        ->toContain('Deprecated|Notice|Warning|Strict Standards|Fatal error')
        // JSON banner stripping.
        ->toContain('first { or [')
        ->toContain('$firstBrace = strpos($joined, \'{\')');
});

/* -----------------------------------------------------------------
 | L10 — gitBranch validation regex. Checks both the positive
 | pattern and the explicit `..` exclusion.
 | ----------------------------------------------------------------- */

it('validates git branch names against a strict refname pattern', function () {
    $php = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/LaravelGitSource.php'));

    expect($php)
        ->toContain("'regex:/^(?!-)(?!\\/)[a-zA-Z0-9._\\/-]+(?<!\\/)(?<!\\.lock)\$/'")
        ->toContain("'not_regex:/\\.\\./'");
});

/* -----------------------------------------------------------------
 | L11 — log truncation window widened from 220 to 500 lines.
 | ----------------------------------------------------------------- */

it('keeps composer and npm logs truncated at 500 lines instead of 220', function () {
    $php = file_get_contents(__DIR__.'/../../'.('app/Livewire/Project/Service/StackForm.php'));

    expect($php)
        ->toContain("sed -n '1,500p'")
        ->not->toContain("sed -n '1,220p'");
});
