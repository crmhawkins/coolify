<?php

use App\Actions\Service\GenerateServiceBackup;
use App\Actions\Service\PruneExpiredServiceBackups;

/**
 * Smoke tests for the per-service WordPress backup action and its
 * sibling pruner. These don't actually run a backup (that requires
 * a live Docker host with a WordPress container), they just lock
 * the public surface so future refactors can't accidentally rename
 * a constant or drop a method that the rest of the fork relies on.
 *
 * The TTL constant in particular is the contract between the
 * action and the prune sweeper — they MUST agree, otherwise the
 * sweeper either runs too eagerly (deleting fresh backups) or
 * not at all (leaking disk).
 */

it('GenerateServiceBackup::TTL_MINUTES is set to 30 (the operator-requested value)', function () {
    expect(GenerateServiceBackup::TTL_MINUTES)->toBe(30);
});

it('GenerateServiceBackup exposes a handle method that takes a ServiceBackupRun', function () {
    $reflection = new \ReflectionClass(GenerateServiceBackup::class);
    expect($reflection->hasMethod('handle'))->toBeTrue();
    $handle = $reflection->getMethod('handle');
    $params = $handle->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getType()?->getName())->toBe(\App\Models\ServiceBackupRun::class);
});

it('PruneExpiredServiceBackups orphan window is generous enough to not eat fresh backups', function () {
    // The orphan sweep deletes .zip files older than this many
    // minutes that have no DB row pointing at them. It MUST be
    // strictly greater than GenerateServiceBackup::TTL_MINUTES so
    // a backup that just finished, hasn't yet been "completed" in
    // the DB row (race window), and has its zip already on disk
    // never gets eaten by the sweeper.
    expect(PruneExpiredServiceBackups::ORPHAN_AFTER_MINUTES)
        ->toBeGreaterThan(GenerateServiceBackup::TTL_MINUTES);
});

it('GenerateServiceBackup::guardWordPressOnly is a private method (defense-in-depth not bypassable from outside)', function () {
    $reflection = new \ReflectionClass(GenerateServiceBackup::class);
    expect($reflection->hasMethod('guardWordPressOnly'))->toBeTrue();
    expect($reflection->getMethod('guardWordPressOnly')->isPrivate())->toBeTrue();
});

it('PruneExpiredServiceBackups exposes a handle method', function () {
    $reflection = new \ReflectionClass(PruneExpiredServiceBackups::class);
    expect($reflection->hasMethod('handle'))->toBeTrue();
});
