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
            // 1. Verify acme.json exists on the remote host. We use
            //    a bare `test && echo ok || echo notfound` command
            //    without any sh -c wrapper so quoting stays simple.
            $existsCheck = 'test -f '.escapeshellarg($acmePath).' && echo ok || echo notfound';
            if ($server->isNonRoot()) {
                $existsCheck = 'sudo '.$existsCheck;
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

            // 2. Backup acme.json before we touch it. Uses plain
            //    `cp` on the remote host — no PHP binary required.
            $backupCmd = 'cp '.escapeshellarg($acmePath).' '.escapeshellarg($backupPath);
            if ($server->isNonRoot()) {
                $backupCmd = "sudo {$backupCmd}";
            }
            instant_remote_process([$backupCmd], $server, false);

            // 3. Read acme.json via `cat`. The previous implementation
            //    tried to upload a PHP script to the remote host and
            //    run it with the `php` binary, but Coolify hosts
            //    don't have PHP installed — PHP lives inside the
            //    coolify container, not on the host. The `cat` →
            //    process in Coolify PHP → write back flow has zero
            //    dependencies on the host toolchain.
            $catCmd = 'cat '.escapeshellarg($acmePath);
            if ($server->isNonRoot()) {
                $catCmd = "sudo {$catCmd}";
            }
            $raw = (string) (instant_remote_process([$catCmd], $server, false) ?? '');
            if ($raw === '') {
                return [
                    'ok' => false,
                    'fqdns' => $fqdns,
                    'removed' => 0,
                    'message' => 'acme.json está vacío o no se pudo leer.',
                    'backup' => $backupPath,
                ];
            }

            // 4. Prune the target FQDN entries from the JSON. This
            //    runs inside the Coolify PHP process, not on the
            //    remote host — all the tricky logic lives in
            //    prunAcmeJson() which is easy to unit-test.
            $pruned = $this->pruneAcmeJson($raw, $fqdns);
            if ($pruned === null) {
                return [
                    'ok' => false,
                    'fqdns' => $fqdns,
                    'removed' => 0,
                    'message' => 'acme.json parsing failed — file is not valid JSON. The backup is intact and unchanged.',
                    'backup' => $backupPath,
                ];
            }

            $removed = (int) $pruned['removed'];
            $kept = (int) $pruned['kept'];

            if ($removed === 0) {
                // Nothing matched — still useful to fall through to
                // the restart step because the cert may be stuck in
                // "pending" state and just need an ACME retry.
                $newContent = $raw;
            } else {
                $newContent = (string) $pruned['content'];

                // 5. Write the pruned JSON back via base64 round-trip
                //    so we never have to worry about quoting. We
                //    write to a .tmp path first and then mv so
                //    Traefik never reads a half-written file (it
                //    polls acme.json periodically). chmod 0600 is
                //    Traefik's required permission.
                $tmpPath = $acmePath.'.coolify-tmp';
                $b64 = escapeshellarg(base64_encode($newContent));
                $tmpArg = escapeshellarg($tmpPath);
                $acmeArg = escapeshellarg($acmePath);

                $writeCmd = "echo {$b64} | base64 -d > {$tmpArg} && chmod 600 {$tmpArg} && mv {$tmpArg} {$acmeArg}";
                if ($server->isNonRoot()) {
                    $writeCmd = "sudo sh -c ".escapeshellarg($writeCmd);
                }
                instant_remote_process([$writeCmd], $server, false);
            }

            // 6. SIGHUP the proxy so Traefik picks up the new
            //    acme.json immediately. Non-fatal if the container
            //    isn't named coolify-proxy (swarm setups, custom
            //    names) — we log and move on to the container
            //    restart step which kicks Traefik via the label
            //    reread path anyway.
            $reloadCmd = 'docker kill --signal=SIGHUP coolify-proxy 2>/dev/null || true';
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

            // 7. Restart the service's application containers so
            //    Traefik re-reads their labels and triggers a fresh
            //    ACME challenge. We only restart the application
            //    containers that actually have FQDNs — databases
            //    (mariadb, phpMyAdmin, redis…) don't need a
            //    restart because they don't expose HTTPS.
            $restarted = 0;
            foreach ($service->applications as $application) {
                $fqdnRaw = (string) ($application->fqdn ?? '');
                if ($fqdnRaw === '') {
                    continue;
                }
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

            if ($removed === 0) {
                return [
                    'ok' => true,
                    'fqdns' => $fqdns,
                    'removed' => 0,
                    'restarted' => $restarted,
                    'backup' => $backupPath,
                    'message' => "Ninguna entrada de acme.json coincidió con los dominios de este servicio — puede que el certificado aún no se haya emitido. He reiniciado {$restarted} contenedor(es) para disparar un nuevo ACME challenge. Espera 30-90 segundos y recarga HTTPS.",
                ];
            }

            return [
                'ok' => true,
                'fqdns' => $fqdns,
                'removed' => $removed,
                'restarted' => $restarted,
                'backup' => $backupPath,
                'message' => "Podadas {$removed} entrada(s) de acme.json para ".count($fqdns).' dominio(s), '.$restarted.' contenedor(es) reiniciado(s). El nuevo certificado tarda 30-90 segundos en emitirse.',
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
     * Pure-PHP pruner. Takes the raw acme.json content + a list of
     * target FQDNs and returns the rewritten JSON with matching
     * certificates removed, or null if the input is not valid JSON.
     *
     * Exposed as a public method so the unit tests can feed it
     * synthetic acme.json payloads directly without touching SSH.
     *
     * Traefik's acme.json v2 format:
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
     * @param  array<int, string>  $targetFqdns
     * @return array{content: string, removed: int, kept: int}|null
     */
    public function pruneAcmeJson(string $raw, array $targetFqdns): ?array
    {
        $data = json_decode(trim($raw), true);
        if (! is_array($data)) {
            return null;
        }

        $targets = array_values(array_filter(
            array_map(fn ($s) => strtolower(trim((string) $s)), $targetFqdns),
            fn ($s) => $s !== ''
        ));
        if (empty($targets)) {
            return ['content' => $raw, 'removed' => 0, 'kept' => 0];
        }

        $removed = 0;
        $kept = 0;

        foreach ($data as $resolver => $resolverData) {
            if (! is_array($resolverData)) {
                continue;
            }
            $certs = $resolverData['Certificates'] ?? null;
            if (! is_array($certs)) {
                continue;
            }

            $newCerts = [];
            foreach ($certs as $cert) {
                if (! is_array($cert)) {
                    $newCerts[] = $cert;
                    $kept++;

                    continue;
                }
                $domain = $cert['domain'] ?? [];
                $main = strtolower((string) ($domain['main'] ?? ''));
                $sans = [];
                if (isset($domain['sans']) && is_array($domain['sans'])) {
                    foreach ($domain['sans'] as $san) {
                        $sans[] = strtolower((string) $san);
                    }
                }

                $matches = in_array($main, $targets, true);
                if (! $matches) {
                    foreach ($sans as $san) {
                        if (in_array($san, $targets, true)) {
                            $matches = true;
                            break;
                        }
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

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return null;
        }

        return [
            'content' => $encoded,
            'removed' => $removed,
            'kept' => $kept,
        ];
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

}
