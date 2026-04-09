<?php

namespace App\Actions\Service;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Forces Traefik to re-issue the TLS certificates for every FQDN
 * that belongs to a given Service. Use this when HTTPS is broken
 * after a domain change, DNS propagation delay, Let's Encrypt
 * rate limit recovery, or a stuck "pending" cert in acme.json.
 *
 * What it does, in order
 * ----------------------
 * 1. Enumerates the FQDNs exposed by every ServiceApplication of
 *    the Service. Bail out early if there are none (nothing to
 *    regenerate, the service is not HTTPS-exposed).
 *
 * 2. Backs up /traefik/acme.json to acme.json.backup-YYYYmmdd-HHMMSS
 *    inside the proxy volume, so if anything goes wrong the user
 *    (or we) can restore it manually with a cp.
 *
 * 3. Uses a small embedded PHP helper to rewrite acme.json in
 *    place: loads the file, decodes the JSON, removes every
 *    certificate / resolution entry whose `domain.main` (or
 *    `domain.sans[*]`) matches one of the target FQDNs, and
 *    writes the pruned JSON back. This is the surgical operation
 *    that makes Traefik re-request these specific certs without
 *    affecting other sites on the same server.
 *
 * 4. Soft-reloads Traefik by signalling SIGHUP to the process
 *    inside coolify-proxy. Traefik's file provider has
 *    `providers.file.watch=true`, and since we also touched the
 *    storage file, the proxy re-evaluates its routers.
 *
 * 5. Issues a plain `docker restart` on every ServiceApplication
 *    container so Traefik re-reads their labels and issues a
 *    fresh ACME challenge.
 *
 * Why not "rm acme.json" or restart the proxy fully?
 * --------------------------------------------------
 *   - Full acme.json wipe is destructive: every OTHER service on
 *     the same server loses its certs too and has to re-issue,
 *     potentially hitting the Let's Encrypt weekly rate limit of
 *     50 certs per domain.
 *   - Full proxy restart interrupts ALL HTTP traffic on the
 *     server for a few seconds.
 *   - The surgical "prune only this domain's entries" approach
 *     limits the blast radius to exactly the service the user
 *     clicked Re-SSL on.
 *
 * Scope
 * -----
 * Traefik only. Caddy and Nginx proxies return early with an
 * "unsupported" message — adding those is future work if needed
 * (Caddy has a different cert cache format, nginx does not
 * manage certs itself).
 */
class RegenerateSslForService
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * @return array{ok: bool, fqdns: array<int, string>, removed: int, message: string, backup: ?string}
     */
    public function handle(Service $service): array
    {
        $server = $service->server;
        if (! $server) {
            return [
                'ok' => false,
                'fqdns' => [],
                'removed' => 0,
                'message' => 'Service has no server attached.',
                'backup' => null,
            ];
        }

        // Only Traefik is supported right now. Caddy stores certs
        // in a completely different format and Nginx does not
        // manage certs on its own.
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return [
                'ok' => false,
                'fqdns' => [],
                'removed' => 0,
                'message' => "Re-SSL is only implemented for Traefik. This server is using {$server->proxyType()}.",
                'backup' => null,
            ];
        }

        $fqdns = $this->collectFqdns($service);
        if (empty($fqdns)) {
            return [
                'ok' => false,
                'fqdns' => [],
                'removed' => 0,
                'message' => 'Ningún FQDN detectado en las aplicaciones del servicio. Añade un dominio HTTPS en Environment Variables o en la configuración de la aplicación y vuelve a intentarlo.',
                'backup' => null,
            ];
        }

        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $acmePath = $proxyPath.'/acme.json';
        $backupName = 'acme.json.backup-'.now()->format('Ymd-His');
        $backupPath = $proxyPath.'/'.$backupName;

        try {
            // Sanity-check that acme.json exists before we touch it.
            $existsCheck = "test -f ".escapeshellarg($acmePath)." && echo ok || echo notfound";
            if ($server->isNonRoot()) {
                $existsCheck = "sudo sh -c ".escapeshellarg($existsCheck);
            } else {
                $existsCheck = "sh -c ".escapeshellarg($existsCheck);
            }
            $existsResult = trim((string) (instant_remote_process([$existsCheck], $server, false) ?? ''));
            if ($existsResult !== 'ok') {
                return [
                    'ok' => false,
                    'fqdns' => $fqdns,
                    'removed' => 0,
                    'message' => "acme.json no encontrado en {$acmePath}. ¿El proxy Traefik está configurado en este servidor?",
                    'backup' => null,
                ];
            }

            // Backup first.
            $backupCmd = 'cp '.escapeshellarg($acmePath).' '.escapeshellarg($backupPath);
            if ($server->isNonRoot()) {
                $backupCmd = "sudo {$backupCmd}";
            }
            instant_remote_process([$backupCmd], $server, false);

            // Prune the target FQDN entries from acme.json via an
            // embedded PHP helper (Traefik containers have Linux
            // with docker but not necessarily jq — doing it in PHP
            // from the HOST side, not the container, is simpler).
            // We upload the script to /tmp on the host, run it
            // with php, then remove it.
            $script = $this->buildPrunePhpScript();
            $scriptPath = '/tmp/coolify-re-ssl-'.uniqid('', true).'.php';
            $scriptB64 = escapeshellarg(base64_encode($script));
            $writeScript = 'sh -c '.escapeshellarg("echo {$scriptB64} | base64 -d > {$scriptPath}");
            if ($server->isNonRoot()) {
                $writeScript = "sudo {$writeScript}";
            }
            instant_remote_process([$writeScript], $server, false);

            $fqdnsArg = escapeshellarg(implode(',', $fqdns));
            $acmeArg = escapeshellarg($acmePath);
            $runScript = "php {$scriptPath} {$acmeArg} {$fqdnsArg} 2>&1";
            if ($server->isNonRoot()) {
                $runScript = "sudo {$runScript}";
            }
            $output = (string) (instant_remote_process([$runScript], $server, false) ?? '');

            // Cleanup script file on the host regardless of
            // success. Best-effort.
            try {
                $cleanup = 'rm -f '.escapeshellarg($scriptPath);
                if ($server->isNonRoot()) {
                    $cleanup = "sudo {$cleanup}";
                }
                instant_remote_process([$cleanup], $server, false);
            } catch (\Throwable) {
            }

            $payload = json_decode(trim($output), true);
            if (! is_array($payload) || ! isset($payload['ok'])) {
                return [
                    'ok' => false,
                    'fqdns' => $fqdns,
                    'removed' => 0,
                    'message' => 'Respuesta del script de poda inválida: '.substr($output, 0, 300),
                    'backup' => $backupPath,
                ];
            }
            if ($payload['ok'] !== true) {
                return [
                    'ok' => false,
                    'fqdns' => $fqdns,
                    'removed' => 0,
                    'message' => (string) ($payload['message'] ?? 'Error podando acme.json'),
                    'backup' => $backupPath,
                ];
            }

            $removed = (int) ($payload['removed'] ?? 0);

            // Signal SIGHUP to Traefik so it picks up the new
            // acme.json immediately. Traefik's default signal
            // handler reloads the dynamic config on SIGHUP. Fails
            // silently if the proxy container is not running.
            $reloadCmd = "docker kill --signal=SIGHUP coolify-proxy 2>/dev/null || true";
            if ($server->isNonRoot()) {
                $reloadCmd = "sudo {$reloadCmd}";
            }
            try {
                instant_remote_process([$reloadCmd], $server, false);
            } catch (\Throwable $e) {
                Log::info('RegenerateSslForService: proxy SIGHUP failed (non-fatal)', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Restart the service's application containers so
            // Traefik re-reads their labels and triggers a fresh
            // ACME challenge for the target domains.
            $restarted = 0;
            foreach ($service->applications as $application) {
                try {
                    $application->restart();
                    $restarted++;
                } catch (\Throwable $e) {
                    Log::warning('RegenerateSslForService: container restart failed', [
                        'application_id' => $application->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'ok' => true,
                'fqdns' => $fqdns,
                'removed' => $removed,
                'restarted' => $restarted,
                'backup' => $backupPath,
                'message' => "Podadas {$removed} entradas de acme.json para ".count($fqdns)." dominio(s), {$restarted} contenedor(es) reiniciado(s). El nuevo certificado tarda 30-90 segundos en emitirse.",
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'fqdns' => $fqdns,
                'removed' => 0,
                'message' => 'Error regenerando SSL: '.$e->getMessage(),
                'backup' => $backupPath ?? null,
            ];
        }
    }

    /**
     * Collects every non-empty FQDN from the service's applications.
     * A single application can declare multiple FQDNs separated by
     * commas (Coolify convention), so we split and normalise each
     * one: strip http/https scheme, trim whitespace, drop empties.
     *
     * @return array<int, string>
     */
    public function collectFqdns(Service $service): array
    {
        $all = [];
        foreach ($service->applications as $application) {
            $raw = (string) ($application->fqdn ?? '');
            if ($raw === '') {
                continue;
            }
            foreach (explode(',', $raw) as $candidate) {
                $normalised = $this->normaliseFqdn($candidate);
                if ($normalised !== '') {
                    $all[] = $normalised;
                }
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * Strip scheme, path, port, query. Returns just the host part,
     * lowercased. Exposed as a public method so tests can exercise
     * it without building a full Service.
     */
    public static function normaliseFqdn(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        // Prepend scheme so parse_url can resolve relative hosts.
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            $value = 'https://'.$value;
        }
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }
        // Drop any leading * from wildcard certs — acme.json stores
        // the wildcard with the literal asterisk, matching it
        // requires the exact string the user entered.
        return $host;
    }

    /**
     * Returns the embedded PHP script that prunes acme.json entries
     * matching any of the target FQDNs. Public for testability —
     * both the script shape and the removal logic are covered by
     * unit tests that feed it synthetic acme.json payloads.
     *
     * The script accepts 2 positional arguments:
     *   $argv[1] = path to acme.json
     *   $argv[2] = comma-separated list of FQDNs to prune
     *
     * Output: single JSON line to stdout with
     *   {ok, removed, kept, message}
     *
     * Traefik's acme.json structure (v2):
     *   {
     *     "<resolverName>": {
     *       "Account": {...},
     *       "Certificates": [
     *         { "domain": { "main": "foo.com", "sans": ["www.foo.com"] },
     *           "certificate": "...", "key": "..." },
     *         ...
     *       ]
     *     }
     *   }
     *
     * We remove any certificate whose domain.main OR any entry in
     * domain.sans matches one of the target FQDNs. This is
     * case-insensitive.
     */
    public function buildPrunePhpScript(): string
    {
        return <<<'PHP'
<?php
// Runs on the HOST (not inside the container) as root so it can
// read/write the acme.json file that Traefik stores under
// /data/coolify/proxy/acme.json (or wherever proxy_path points).
error_reporting(E_ERROR | E_PARSE);

function out($payload) { echo json_encode($payload); exit; }

$acmePath = $argv[1] ?? '';
$targetsRaw = $argv[2] ?? '';
if ($acmePath === '' || $targetsRaw === '') {
    out(['ok' => false, 'message' => 'Missing arguments']);
}

$targets = array_values(array_filter(
    array_map(
        fn ($s) => strtolower(trim((string) $s)),
        explode(',', $targetsRaw)
    ),
    fn ($s) => $s !== ''
));
if (empty($targets)) {
    out(['ok' => false, 'message' => 'No target FQDNs provided']);
}

$raw = @file_get_contents($acmePath);
if ($raw === false) {
    out(['ok' => false, 'message' => 'Could not read ' . $acmePath]);
}

// acme.json is usually JSON, but Traefik writes it with trailing
// whitespace / newlines. json_decode handles that fine, but we
// still trim to be safe.
$data = json_decode(trim($raw), true);
if (! is_array($data)) {
    out(['ok' => false, 'message' => 'acme.json is not valid JSON']);
}

$removed = 0;
$kept = 0;

foreach ($data as $resolver => $resolverData) {
    if (! is_array($resolverData)) continue;
    $certs = $resolverData['Certificates'] ?? null;
    if (! is_array($certs)) continue;

    $newCerts = [];
    foreach ($certs as $cert) {
        if (! is_array($cert)) { $newCerts[] = $cert; $kept++; continue; }
        $domain = $cert['domain'] ?? [];
        $main = strtolower((string) ($domain['main'] ?? ''));
        $sans = array_map(
            fn ($s) => strtolower((string) $s),
            is_array($domain['sans'] ?? null) ? $domain['sans'] : []
        );
        $matches = in_array($main, $targets, true);
        if (! $matches) {
            foreach ($sans as $san) {
                if (in_array($san, $targets, true)) { $matches = true; break; }
            }
        }
        if ($matches) {
            $removed++;
        } else {
            $newCerts[] = $cert;
            $kept++;
        }
    }
    $data[$resolver]['Certificates'] = $newCerts;
}

if ($removed === 0) {
    out([
        'ok' => true,
        'removed' => 0,
        'kept' => $kept,
        'message' => 'No matching certificates found in acme.json — nothing to prune. Certificates may be pending issuance; restarting containers will retry the ACME challenge.',
    ]);
}

// Write the pruned JSON back. Keep the format Traefik uses:
// pretty-printed with 2-space indent (Traefik writes it with
// JSON_PRETTY_PRINT by default).
$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    out(['ok' => false, 'message' => 'Could not re-encode acme.json: ' . json_last_error_msg()]);
}

// Atomic write: write to .tmp first, then rename. This prevents
// Traefik from reading a half-written file if it polls during the
// write. acme.json must be mode 0600 per Traefik's requirement.
$tmpPath = $acmePath . '.coolify-tmp';
if (@file_put_contents($tmpPath, $encoded) === false) {
    out(['ok' => false, 'message' => 'Could not write ' . $tmpPath]);
}
@chmod($tmpPath, 0600);
if (! @rename($tmpPath, $acmePath)) {
    @unlink($tmpPath);
    out(['ok' => false, 'message' => 'Could not rename ' . $tmpPath . ' to ' . $acmePath]);
}

out([
    'ok' => true,
    'removed' => $removed,
    'kept' => $kept,
    'message' => "Pruned {$removed} certificate(s), kept {$kept}",
]);
PHP;
    }
}
