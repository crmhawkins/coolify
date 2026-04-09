<?php

use App\Actions\Service\RegenerateSslForService;

/*
|--------------------------------------------------------------------------
| Re-SSL action (RegenerateSslForService) unit tests
|--------------------------------------------------------------------------
|
| Covers the pure-logic bits of the Re-SSL button flow: FQDN
| normalisation, the in-process acme.json pruner, and the Heading.php
| wiring. SSH and actual docker/Traefik interactions are not exercised
| here — those require a real proxy and are verified manually in
| production.
|
*/

/* -----------------------------------------------------------------
 | FQDN normalisation
 | ----------------------------------------------------------------- */

it('normaliseFqdn strips scheme, port and path', function () {
    expect(RegenerateSslForService::normaliseFqdn('https://casadelavirgen.hawkins.es'))
        ->toBe('casadelavirgen.hawkins.es');
    expect(RegenerateSslForService::normaliseFqdn('http://foo.example.com'))
        ->toBe('foo.example.com');
    expect(RegenerateSslForService::normaliseFqdn('https://foo.example.com:8080/some/path'))
        ->toBe('foo.example.com');
});

it('normaliseFqdn accepts bare host strings without a scheme', function () {
    expect(RegenerateSslForService::normaliseFqdn('foo.example.com'))
        ->toBe('foo.example.com');
    expect(RegenerateSslForService::normaliseFqdn('  bar.example.com  '))
        ->toBe('bar.example.com');
});

it('normaliseFqdn lowercases the host', function () {
    expect(RegenerateSslForService::normaliseFqdn('https://Casa.HAWKINS.ES'))
        ->toBe('casa.hawkins.es');
});

it('normaliseFqdn returns empty on empty input', function () {
    expect(RegenerateSslForService::normaliseFqdn(''))->toBe('');
    expect(RegenerateSslForService::normaliseFqdn('   '))->toBe('');
});

/* -----------------------------------------------------------------
 | pruneAcmeJson: core pruning logic
 | -----------------------------------------------------------------
 |
 | The pruner runs in-process inside the Coolify Livewire worker, not
 | in a remote shell script. That lets us unit-test it directly with
 | synthetic acme.json payloads without touching SSH or docker. This
 | is the key change vs the previous "upload PHP script, run with
 | `php`" design — the new version is both easier to test AND doesn't
 | require PHP on the remote host.
 */

it('pruneAcmeJson removes certificates whose main domain matches a target', function () {
    $action = new RegenerateSslForService();

    $acme = json_encode([
        'letsencrypt' => [
            'Account' => ['Email' => 'test@example.com'],
            'Certificates' => [
                ['domain' => ['main' => 'findpartners.es', 'sans' => []], 'certificate' => 'PEM_A', 'key' => 'KEY_A'],
                ['domain' => ['main' => 'other.example.com', 'sans' => []], 'certificate' => 'PEM_B', 'key' => 'KEY_B'],
                ['domain' => ['main' => 'another.example.com'], 'certificate' => 'PEM_C', 'key' => 'KEY_C'],
            ],
        ],
    ]);

    $result = $action->pruneAcmeJson($acme, ['findpartners.es']);

    expect($result)->not->toBeNull();
    expect($result['removed'])->toBe(1);
    expect($result['kept'])->toBe(2);

    $decoded = json_decode($result['content'], true);
    $mains = array_map(fn ($c) => $c['domain']['main'] ?? '', $decoded['letsencrypt']['Certificates']);
    expect($mains)->toContain('other.example.com');
    expect($mains)->toContain('another.example.com');
    expect($mains)->not->toContain('findpartners.es');
});

it('pruneAcmeJson also matches on SANs (multi-domain certs)', function () {
    $action = new RegenerateSslForService();

    $acme = json_encode([
        'letsencrypt' => [
            'Certificates' => [
                [
                    'domain' => [
                        'main' => 'crm.apartamentosalgeciras.com',
                        'sans' => ['apartamentosalgeciras.com', 'www.apartamentosalgeciras.com'],
                    ],
                    'certificate' => 'PEM_COMBINED',
                ],
                [
                    'domain' => ['main' => 'other.example.com', 'sans' => []],
                    'certificate' => 'PEM_OTHER',
                ],
            ],
        ],
    ]);

    // Match on a SAN, not on the main domain.
    $result = $action->pruneAcmeJson($acme, ['www.apartamentosalgeciras.com']);

    expect($result['removed'])->toBe(1);
    expect($result['kept'])->toBe(1);

    $decoded = json_decode($result['content'], true);
    $mains = array_map(fn ($c) => $c['domain']['main'] ?? '', $decoded['letsencrypt']['Certificates']);
    expect($mains)->toBe(['other.example.com']);
});

it('pruneAcmeJson is case-insensitive on both main and SANs', function () {
    $action = new RegenerateSslForService();

    $acme = json_encode([
        'letsencrypt' => [
            'Certificates' => [
                ['domain' => ['main' => 'FINDPARTNERS.ES', 'sans' => []], 'certificate' => 'PEM'],
            ],
        ],
    ]);

    $result = $action->pruneAcmeJson($acme, ['findpartners.es']);
    expect($result['removed'])->toBe(1);
    expect($result['kept'])->toBe(0);
});

it('pruneAcmeJson returns removed=0 and leaves content untouched when nothing matches', function () {
    $action = new RegenerateSslForService();

    $original = json_encode([
        'letsencrypt' => [
            'Certificates' => [
                ['domain' => ['main' => 'keep-me.example.com', 'sans' => []], 'certificate' => 'PEM'],
            ],
        ],
    ]);

    $result = $action->pruneAcmeJson($original, ['nomatch.example.com']);

    expect($result['removed'])->toBe(0);
    expect($result['kept'])->toBe(1);

    // Content is re-encoded but semantically identical.
    $originalDecoded = json_decode($original, true);
    $resultDecoded = json_decode($result['content'], true);
    expect($resultDecoded)->toBe($originalDecoded);
});

it('pruneAcmeJson returns null on invalid JSON', function () {
    $action = new RegenerateSslForService();

    expect($action->pruneAcmeJson('not-json-at-all', ['foo.example.com']))->toBeNull();
    expect($action->pruneAcmeJson('', ['foo.example.com']))->toBeNull();
    expect($action->pruneAcmeJson('{', ['foo.example.com']))->toBeNull();
});

it('pruneAcmeJson handles multiple resolvers in the same acme.json', function () {
    $action = new RegenerateSslForService();

    $acme = json_encode([
        'letsencrypt' => [
            'Certificates' => [
                ['domain' => ['main' => 'findpartners.es', 'sans' => []], 'certificate' => 'PEM1'],
                ['domain' => ['main' => 'keep1.example.com', 'sans' => []], 'certificate' => 'PEM2'],
            ],
        ],
        'zerossl' => [
            'Certificates' => [
                ['domain' => ['main' => 'findpartners.es', 'sans' => []], 'certificate' => 'PEM3'],
                ['domain' => ['main' => 'keep2.example.com', 'sans' => []], 'certificate' => 'PEM4'],
            ],
        ],
    ]);

    $result = $action->pruneAcmeJson($acme, ['findpartners.es']);
    // One match per resolver.
    expect($result['removed'])->toBe(2);
    expect($result['kept'])->toBe(2);

    $decoded = json_decode($result['content'], true);
    expect(count($decoded['letsencrypt']['Certificates']))->toBe(1);
    expect(count($decoded['zerossl']['Certificates']))->toBe(1);
    expect($decoded['letsencrypt']['Certificates'][0]['domain']['main'])->toBe('keep1.example.com');
    expect($decoded['zerossl']['Certificates'][0]['domain']['main'])->toBe('keep2.example.com');
});

it('pruneAcmeJson ignores resolvers with no Certificates array (e.g. only an Account block)', function () {
    $action = new RegenerateSslForService();

    $acme = json_encode([
        'letsencrypt' => [
            'Account' => ['Email' => 'a@b.c'],
            // No Certificates key at all yet — this happens right
            // after Traefik creates the account but before any cert
            // has been issued.
        ],
    ]);

    $result = $action->pruneAcmeJson($acme, ['any.example.com']);
    expect($result['removed'])->toBe(0);
    expect($result['kept'])->toBe(0);
});

/* -----------------------------------------------------------------
 | Heading.php + blade wiring
 | ----------------------------------------------------------------- */

it('Heading.php exposes a public regenerateSsl method that delegates to the action', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/Heading.php');

    expect($source)
        ->toContain('use App\\Actions\\Service\\RegenerateSslForService;')
        ->toContain('public function regenerateSsl')
        ->toContain('RegenerateSslForService::run($this->service)')
        // Authorizes update on the service before running.
        ->toMatch('/regenerateSsl.*?\$this->authorize\(.update.,\s*\$this->service\)/s');
});

it('heading.blade.php renders the Re-SSL button in both running and degraded branches', function () {
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/heading.blade.php');

    // The button text + event dispatch
    expect(substr_count($blade, ">Re-SSL<"))->toBe(2, 'Expected Re-SSL button in both running and degraded branches');
    expect($blade)->toContain("\$wire.dispatch('regenerateSslEvent')");
    // The padlock icon path (rectangular body + arc for the shackle)
    expect($blade)->toContain('M7 11V7a5 5 0 0 1 10 0v4');
    // The JS handler that confirms + calls the Livewire method
    expect($blade)
        ->toContain("\$wire.\$on('regenerateSslEvent'")
        ->toContain("\$wire.\$call('regenerateSsl')");
});

it('Re-SSL action is surgical: never touches other services on the same server', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Service/RegenerateSslForService.php');

    // pruneAcmeJson must iterate Certificates and only drop those
    // whose main/sans match the target FQDNs. A global wipe would
    // contain things like `unset($data[$resolver]['Certificates'])`
    // or `$data[$resolver]['Certificates'] = []` unconditionally.
    expect($source)
        // We build a new array of kept certs, we do not wipe the slot.
        ->toContain('$newCerts = []')
        ->toContain("\$data[\$resolver]['Certificates'] = \$newCerts")
        // The match logic uses in_array against the target list.
        ->toContain('in_array($main, $targets')
        // The FQDN collector only reads from $service->applications,
        // never iterates servers or databases globally.
        ->toContain('foreach ($service->applications as $application)')
        // Only restarts containers that actually expose a FQDN —
        // databases without a domain are skipped so they do not
        // cycle unnecessarily.
        ->toContain("\$fqdnRaw = (string) (\$application->fqdn ?? '')");

    // Sanity: no destructive wipe patterns.
    expect($source)
        ->not->toContain('rm -f /traefik/acme.json')
        ->not->toContain('rm /data/coolify/proxy/acme.json');
});

it('Re-SSL action no longer depends on a remote PHP binary', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Service/RegenerateSslForService.php');

    // The previous implementation uploaded a PHP script to /tmp on
    // the remote host and ran it with `php`. Coolify hosts do NOT
    // have PHP installed, so that path failed in production with
    // "Respuesta del script de poda inválida". The new path reads
    // acme.json via `cat`, prunes in-process (Coolify PHP worker),
    // writes it back via base64 echo.
    expect($source)
        // Reads via cat.
        ->toContain('$catCmd = \'cat \'.escapeshellarg($acmePath)')
        // Prunes in-process.
        ->toContain('$this->pruneAcmeJson($raw, $fqdns)')
        // Writes back atomically via base64 + tmp + mv.
        ->toContain('echo {$b64} | base64 -d > {$tmpArg}')
        ->toContain('chmod 600 {$tmpArg}')
        ->toContain('mv {$tmpArg} {$acmeArg}');

    // Must NOT contain the old "run php on the remote host" pattern.
    expect($source)
        ->not->toContain('buildPrunePhpScript')
        ->not->toMatch('/php \{\$scriptPath\}/');
});

it('updateWpPrefix auto-detects the real prefix from WordPress core tables when wp-config.php is out of sync', function () {
    $source = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/WordPressManager.php');

    // The rewrite adds a fallback: if SHOW TABLES LIKE oldprefix%
    // returns empty (wp-config.php and DB are out of sync) we scan
    // all tables and detect the real prefix by looking for core
    // WordPress tables (*_posts, *_users, *_options, *_postmeta,
    // *_usermeta).
    expect($source)
        ->toContain("\$coreSuffixes = ['_posts', '_users', '_options', '_postmeta', '_usermeta']")
        ->toContain('Auto-detect: scan all tables')
        ->toContain('auto-detected prefix')
        // Still falls back to a precise error message when nothing
        // matches.
        ->toContain('No tables with prefix');
});
