<?php

use App\Actions\Service\RegenerateSslForService;

/*
|--------------------------------------------------------------------------
| Re-SSL action (RegenerateSslForService) unit tests
|--------------------------------------------------------------------------
|
| Covers the pure-logic bits of the Re-SSL button flow: FQDN
| normalisation, the embedded PHP pruning script, and the Heading.php
| wiring. SSH / docker / acme.json interactions are NOT exercised here
| because they require a real proxy — those are exercised in a manual
| smoke test and in production.
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
 | Embedded prune script: shape + behaviour
 | ----------------------------------------------------------------- */

it('buildPrunePhpScript returns a valid PHP script with the expected pruning logic', function () {
    $script = (new RegenerateSslForService())->buildPrunePhpScript();

    expect($script)
        // Starts with <?php and defines the out() helper
        ->toStartWith('<?php')
        ->toContain('function out(')
        // Reads argv[1] = acme path, argv[2] = target fqdns
        ->toContain("\$acmePath = \$argv[1]")
        ->toContain("\$targetsRaw = \$argv[2]")
        // Decodes acme.json and iterates resolvers / certificates
        ->toContain("json_decode(")
        ->toContain("'Certificates'")
        // Matches on domain.main AND domain.sans
        ->toContain("'main'")
        ->toContain("'sans'")
        // Atomic write (tmp + rename) + chmod 0600 per Traefik requirement
        ->toContain('.coolify-tmp')
        ->toContain('chmod($tmpPath, 0600)')
        ->toContain('rename($tmpPath, $acmePath)')
        // JSON output with removed/kept counts
        ->toContain("'removed'")
        ->toContain("'kept'");
});

it('buildPrunePhpScript is syntactically valid PHP', function () {
    $script = (new RegenerateSslForService())->buildPrunePhpScript();

    // Save to a temp file and ask the local PHP binary to lint it.
    $tmp = tempnam(sys_get_temp_dir(), 'coolify-prune-test-').'.php';
    file_put_contents($tmp, $script);
    try {
        $output = [];
        $returnVar = 0;
        exec('php -l '.escapeshellarg($tmp).' 2>&1', $output, $returnVar);
        $joined = implode("\n", $output);
        expect($returnVar)->toBe(0, 'Embedded prune script failed php -l: '.$joined);
        expect($joined)->toContain('No syntax errors detected');
    } finally {
        @unlink($tmp);
    }
});

it('embedded prune script correctly prunes matching certificates and keeps the rest', function () {
    // Run the embedded script against a synthetic acme.json with 3
    // certificates and verify only the matching ones are removed.
    $script = (new RegenerateSslForService())->buildPrunePhpScript();

    $scriptPath = tempnam(sys_get_temp_dir(), 'coolify-prune-script-').'.php';
    $acmePath = tempnam(sys_get_temp_dir(), 'coolify-acme-test-').'.json';
    file_put_contents($scriptPath, $script);
    file_put_contents($acmePath, json_encode([
        'letsencrypt' => [
            'Account' => ['Email' => 'test@example.com'],
            'Certificates' => [
                [
                    'domain' => ['main' => 'casadelavirgen.hawkins.es', 'sans' => ['www.casadelavirgen.hawkins.es']],
                    'certificate' => 'PEM_A',
                    'key' => 'KEY_A',
                ],
                [
                    'domain' => ['main' => 'other-site.example.com', 'sans' => []],
                    'certificate' => 'PEM_B',
                    'key' => 'KEY_B',
                ],
                [
                    'domain' => ['main' => 'apartamentos.hawkins.es'],
                    'certificate' => 'PEM_C',
                    'key' => 'KEY_C',
                ],
            ],
        ],
    ]));

    try {
        $output = [];
        $returnVar = 0;
        exec(
            'php '.escapeshellarg($scriptPath).' '.escapeshellarg($acmePath)
            .' '.escapeshellarg('casadelavirgen.hawkins.es,www.casadelavirgen.hawkins.es').' 2>&1',
            $output,
            $returnVar
        );

        $joined = implode("\n", $output);
        $payload = json_decode(trim($joined), true);

        expect($payload)->toBeArray();
        expect($payload['ok'] ?? false)->toBeTrue("Script failed: $joined");
        expect($payload['removed'] ?? null)->toBe(1);
        expect($payload['kept'] ?? null)->toBe(2);

        // Verify the file was actually rewritten with only the kept certs.
        $pruned = json_decode((string) file_get_contents($acmePath), true);
        $remaining = $pruned['letsencrypt']['Certificates'] ?? [];
        expect(count($remaining))->toBe(2);

        $mains = array_map(fn ($c) => $c['domain']['main'] ?? '', $remaining);
        expect($mains)->toContain('other-site.example.com');
        expect($mains)->toContain('apartamentos.hawkins.es');
        expect($mains)->not->toContain('casadelavirgen.hawkins.es');
    } finally {
        @unlink($scriptPath);
        @unlink($acmePath);
    }
});

it('embedded prune script reports "no matches" when nothing matches but leaves the file intact', function () {
    $script = (new RegenerateSslForService())->buildPrunePhpScript();

    $scriptPath = tempnam(sys_get_temp_dir(), 'coolify-prune-script-').'.php';
    $acmePath = tempnam(sys_get_temp_dir(), 'coolify-acme-test-').'.json';
    file_put_contents($scriptPath, $script);

    $originalJson = json_encode([
        'letsencrypt' => [
            'Certificates' => [
                ['domain' => ['main' => 'kept-site.example.com', 'sans' => []], 'certificate' => 'PEM', 'key' => 'KEY'],
            ],
        ],
    ]);
    file_put_contents($acmePath, $originalJson);

    try {
        exec('php '.escapeshellarg($scriptPath).' '.escapeshellarg($acmePath).' '.escapeshellarg('nomatch.example.com').' 2>&1', $output);
        $payload = json_decode(trim(implode("\n", $output)), true);
        expect($payload['ok'] ?? false)->toBeTrue();
        expect($payload['removed'] ?? null)->toBe(0);
        // File unchanged — the script only rewrites when removed > 0.
        expect(trim((string) file_get_contents($acmePath)))->toBe(trim($originalJson));
    } finally {
        @unlink($scriptPath);
        @unlink($acmePath);
    }
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

    // The prune script must iterate Certificates and only drop those
    // whose main/sans match the target FQDNs. A global wipe would
    // contain things like `unset($data[$resolver]['Certificates'])`
    // or `$data[$resolver]['Certificates'] = []` unconditionally.
    expect($source)
        // We build a new array of kept certs, we do not wipe the slot.
        ->toContain('$newCerts = []')
        ->toContain("\$data[\$resolver]['Certificates'] = \$newCerts")
        // The match logic uses in_array against the target list.
        ->toContain('in_array($main, $targets')
        // And the FQDN collector only reads from $service->applications,
        // never iterates servers or databases globally.
        ->toContain('foreach ($service->applications as $application)');

    // Sanity: no "DELETE FROM" or "rm -f acme.json" destructive
    // patterns that would nuke everything.
    expect($source)
        ->not->toContain('rm -f /traefik/acme.json')
        ->not->toContain('rm /data/coolify/proxy/acme.json');
});
