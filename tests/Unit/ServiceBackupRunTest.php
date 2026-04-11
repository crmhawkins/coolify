<?php

use App\Models\ServiceBackupRun;
use Illuminate\Support\Carbon;

/**
 * Pure-logic tests for the ServiceBackupRun model. We don't hit
 * the database here — every property is set on a fresh instance
 * via setRawAttributes so the tests work without migrations.
 */

it('reports downloadable when completed, has artifact_path and not expired', function () {
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'status' => 'completed',
        'artifact_path' => '/data/coolify/backups/services/uuid/file.zip',
        'expires_at' => Carbon::now()->addMinutes(20)->toDateTimeString(),
    ]);

    expect($run->isDownloadable())->toBeTrue();
});

it('is not downloadable while still running', function () {
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'status' => 'running',
        'artifact_path' => '/data/coolify/backups/services/uuid/file.zip',
        'expires_at' => Carbon::now()->addMinutes(20)->toDateTimeString(),
    ]);

    expect($run->isDownloadable())->toBeFalse();
});

it('is not downloadable when failed', function () {
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'status' => 'failed',
        'artifact_path' => null,
        'expires_at' => null,
    ]);

    expect($run->isDownloadable())->toBeFalse();
});

it('is not downloadable when artifact_path is empty', function () {
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'status' => 'completed',
        'artifact_path' => '',
        'expires_at' => Carbon::now()->addMinutes(20)->toDateTimeString(),
    ]);

    expect($run->isDownloadable())->toBeFalse();
});

it('is not downloadable when expires_at is in the past', function () {
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'status' => 'completed',
        'artifact_path' => '/data/coolify/backups/services/uuid/file.zip',
        'expires_at' => Carbon::now()->subMinute()->toDateTimeString(),
    ]);

    expect($run->isDownloadable())->toBeFalse();
});

it('is not downloadable after the prune flipped status to expired', function () {
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'status' => 'expired',
        'artifact_path' => '/data/coolify/backups/services/uuid/file.zip',
        'expires_at' => Carbon::now()->subMinutes(5)->toDateTimeString(),
    ]);

    expect($run->isDownloadable())->toBeFalse();
});

it('returns null duration before the run finishes', function () {
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'started_at' => Carbon::now()->subMinute()->toDateTimeString(),
        'finished_at' => null,
    ]);

    expect($run->durationSeconds())->toBeNull();
});

it('returns a non-negative duration once finished_at is set', function () {
    // 73 seconds elapsed.
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'started_at' => '2026-04-11 12:00:00',
        'finished_at' => '2026-04-11 12:01:13',
    ]);

    expect($run->durationSeconds())->toBe(73);
});

it('returns a non-negative duration even if started_at and finished_at are swapped', function () {
    // Carbon 3 returns SIGNED diffs by default, so this would
    // bite us if we relied on argument order. The model wraps
    // the diff in abs() — make sure that's still in place.
    $run = new ServiceBackupRun;
    $run->setRawAttributes([
        'started_at' => '2026-04-11 12:01:13',
        'finished_at' => '2026-04-11 12:00:00',
    ]);

    expect($run->durationSeconds())->toBe(73);
});
