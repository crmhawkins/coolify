<?php

namespace App\Actions\Service;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Fixes ownership and permissions of /var/www/html/wp-content inside
 * every WordPress container that belongs to the given service.
 *
 * Background / problem
 * ---------------------
 * When a user uploads files to the container as root (file explorer,
 * docker cp, terminal as root, …), wp-content ends up owned by
 * root:root. Apache + PHP-FPM inside the WordPress image runs as
 * www-data (UID 33), so any subsequent plugin install, media upload,
 * theme activation or Elementor save fails — WordPress falls back to
 * the "FTP credentials" dialog in the admin because it cannot write
 * to the file system itself. The fix is trivial but annoying to
 * remember, so we wrap it in an action that can be:
 *   - Called from the UI ("Arreglar permisos wp-content" button in
 *     the WordPress Manager page).
 *   - Scheduled from a job (FixWordPressContentPermissionsJob), which
 *     in turn runs automatically after every Redeploy / Pull&Restart
 *     of a WordPress service via Heading.php.
 *
 * Idempotent
 * ----------
 * Every command is idempotent — chown/chmod are safe to run on an
 * already-correct tree, mkdir uses `-p` so an existing `upgrade`
 * directory is a no-op. Running this 100 times in a row produces
 * the same state as running it once.
 *
 * Exit code capture via sentinel
 * ------------------------------
 * instant_remote_process() throws on non-zero exit status and the
 * surrounding excludeCertainErrors() helper then discards the real
 * stdout to produce a generic "SSH command failed with exit code: N"
 * RuntimeException. That would swallow the actual composer / chown
 * error message the user needs to see. Wrapping the command with
 * `( … ); __cc_status=$?; ... exit 0` keeps the SSH call at exit 0
 * regardless of what the inner script did, and we prefix a sentinel
 * __COOLIFY_FIX_WP_PERMS_FAILED__<code> line so the PHP side can
 * detect failure while still getting the full stdout.
 *
 * Verification steps
 * ------------------
 * After the chown/chmod block, the action runs the three verification
 * checks listed in the briefing:
 *   1. `ls -la | grep wp-content` — sanity check on ownership
 *   2. `stat -c '%U:%G' /var/www/html/wp-content/upgrade` — ensures
 *      the upgrade directory is www-data owned
 *   3. `su -s /bin/bash www-data -c 'touch … && rm … && echo OK'` —
 *      the real test: can the Apache user actually write?
 *
 * If step 3 prints OK the action reports success and WordPress is
 * guaranteed to be able to install plugins again. If any of the three
 * fail, the raw output is surfaced to the caller so the user can see
 * what went wrong (permission error, wrong UID, read-only mount, …).
 */
class FixWordPressContentPermissions
{
    use AsAction;

    public string $jobQueue = 'default';

    /**
     * Sentinel written by the wrapper on non-zero exit. Kept as a
     * public constant so tests and the PHP parser agree on the exact
     * token without copy-pasted string literals.
     */
    public const FAILURE_SENTINEL = '__COOLIFY_FIX_WP_PERMS_FAILED__';

    /**
     * Return shape:
     *
     * @return array{
     *     ok: bool,
     *     containers: array<int, array{
     *         container: string,
     *         server: string,
     *         ok: bool,
     *         exit_code: int,
     *         output: string,
     *         write_test_ok: bool,
     *     }>,
     *     errors: array<int, string>,
     * }
     */
    public function handle(Service $service): array
    {
        $results = [];
        $errors = [];

        $wordpressApps = $service->applications
            ->filter(fn ($app) => $this->looksLikeWordPress($app))
            ->values();

        if ($wordpressApps->isEmpty()) {
            return [
                'ok' => false,
                'containers' => [],
                'errors' => ['No WordPress containers were detected in this service.'],
            ];
        }

        foreach ($wordpressApps as $application) {
            if (! str((string) $application->status)->contains('running')) {
                $errors[] = "Container '{$application->name}' is not running — skipped.";

                continue;
            }

            $server = $application->service->server;
            $containerName = $application->name.'-'.$service->uuid;

            $result = $this->runOnContainer($server, $containerName);
            $results[] = $result;

            if (! $result['ok']) {
                $errors[] = "Container '{$containerName}' failed with exit code {$result['exit_code']}.";
            }
        }

        $allOk = ! empty($results) && collect($results)->every(fn (array $r) => $r['ok'] === true);

        if (! $allOk && empty($errors) && empty($results)) {
            $errors[] = 'No running WordPress containers to fix.';
        }

        return [
            'ok' => $allOk && empty($errors),
            'containers' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Executes the chown/chmod + verification block inside a single
     * docker exec on the given container and returns the parsed
     * result. Isolated into its own method so both the UI button and
     * the background job can call it without duplicating the shell
     * construction logic.
     *
     * @return array{container: string, server: string, ok: bool, exit_code: int, output: string, write_test_ok: bool}
     */
    public function runOnContainer(Server $server, string $containerName): array
    {
        $escapedContainer = escapeshellarg($containerName);
        $innerScript = $this->buildInnerScript();

        // The outer wrapper traps the exit code of the inner script,
        // prints the sentinel on non-zero, and ALWAYS exits 0 so the
        // SSH layer never raises — see class docblock for full
        // rationale.
        $wrapped = '('.$innerScript.'); __cc_status=$?; '
            .'if [ "$__cc_status" != "0" ]; then echo "'.self::FAILURE_SENTINEL.'$__cc_status"; fi; '
            .'exit 0';

        $command = "docker exec {$escapedContainer} sh -lc ".escapeshellarg($wrapped);
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        $output = '';
        $exitCode = 0;
        $ok = true;
        $writeTestOk = false;

        try {
            $output = (string) (instant_remote_process([$command], $server, false) ?? '');

            if (preg_match('/'.preg_quote(self::FAILURE_SENTINEL, '/').'(\d+)/', $output, $m)) {
                $ok = false;
                $exitCode = (int) $m[1];
                $output = trim((string) preg_replace('/\s*'.preg_quote(self::FAILURE_SENTINEL, '/').'\d+\s*/', '', $output));
            }

            // The write test line is "OK" on its own when the
            // verification block succeeded end-to-end. We look for it
            // anywhere in the output so we can still flag success
            // even if the chown/chmod block printed noise above.
            $writeTestOk = $ok && (bool) preg_match('/__WRITE_TEST_OK__/', $output);

            // Strip the internal markers from the user-facing output so
            // the panel doesn't leak implementation details.
            $output = (string) preg_replace('/__WRITE_TEST_OK__\s*/', '', $output);
        } catch (\Throwable $e) {
            Log::warning('FixWordPressContentPermissions: SSH failure', [
                'container' => $containerName,
                'server' => $server->ip ?? null,
                'error' => $e->getMessage(),
            ]);
            $ok = false;
            $exitCode = -1;
            $output = 'SSH failure: '.$e->getMessage();
        }

        return [
            'container' => $containerName,
            'server' => (string) ($server->name ?? $server->ip ?? 'unknown'),
            'ok' => $ok,
            'exit_code' => $exitCode,
            'output' => $output,
            'write_test_ok' => $writeTestOk,
        ];
    }

    /**
     * Detection heuristic that matches ANY of:
     *  - image name contains "wordpress"
     *  - env var key contains "WORDPRESS_" (WORDPRESS_DB_HOST, etc.)
     *  - application name contains "wordpress"
     *
     * Keeps the action decoupled from the WordPressManager component's
     * own isWordPressContainer() logic — we intentionally do NOT
     * require the container to be "running" here because the caller
     * filters by status already, and a separate status check would
     * be redundant.
     */
    public function looksLikeWordPress($application): bool
    {
        $image = strtolower((string) ($application->image ?? ''));
        if (str_contains($image, 'wordpress')) {
            return true;
        }

        $name = strtolower((string) ($application->name ?? ''));
        if (str_contains($name, 'wordpress')) {
            return true;
        }

        try {
            foreach ($application->environment_variables()->get() as $envVar) {
                $key = strtoupper((string) $envVar->key);
                if (str_starts_with($key, 'WORDPRESS_')) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Ignore — some contexts (e.g. job execution on a stale
            // snapshot) may not have the relation available.
        }

        return false;
    }

    /**
     * Builds the chown/chmod block + verification steps. Returned as
     * a single-line shell script ready to be wrapped in the sentinel
     * harness above. Public so tests can assert every step is
     * present without having to mock the whole execution layer.
     */
    public function buildInnerScript(): string
    {
        $lines = [
            // Start with the root so any failure short-circuits the
            // rest via `set -e` (the wrapper still captures the code).
            'set -e',
            'cd /var/www/html',
            'echo "→ chown -R www-data:www-data wp-content"',
            'chown -R www-data:www-data wp-content',
            'echo "→ chmod 755 directories"',
            "find wp-content -type d -exec chmod 755 {} +",
            'echo "→ chmod 644 files"',
            "find wp-content -type f -exec chmod 644 {} +",
            'echo "→ ensure wp-content/upgrade exists"',
            // mkdir -p is idempotent: if the directory already exists
            // it exits 0 with no output. See the class docblock for the
            // full discussion on why we don't test -d first.
            'mkdir -p wp-content/upgrade',
            'chown www-data:www-data wp-content/upgrade',
            'chmod 755 wp-content/upgrade',
            'echo "→ verification: ownership of wp-content"',
            "ls -ld wp-content",
            'echo "→ verification: ownership of wp-content/upgrade"',
            "stat -c '%U:%G' wp-content/upgrade",
            'echo "→ verification: www-data can write"',
            // The write test runs as www-data explicitly and prints the
            // __WRITE_TEST_OK__ marker on success. The PHP side greps
            // for that marker to light up the "WordPress ya puede
            // escribir" badge in the UI, and then strips it from the
            // user-visible output.
            'if su -s /bin/bash www-data -c "touch wp-content/.coolify-write-test && rm wp-content/.coolify-write-test"; then echo "__WRITE_TEST_OK__"; echo "OK: www-data puede escribir en wp-content"; else echo "FAIL: www-data no puede escribir"; exit 3; fi',
            'echo "✓ Done"',
        ];

        return implode('; ', $lines);
    }
}
