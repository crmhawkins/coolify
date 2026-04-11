<?php

use App\Services\MonitorResourceAggregator;

/**
 * Pure-logic tests for the severity bucketing in the monitor
 * aggregator. No DB, no Docker, no Livewire — just the static
 * method that maps a Coolify colon-format status string to one
 * of {ok, warning, critical}. This is the contract that drives
 * the alerts bell counters and the resource row borders in the
 * Monitor page, so it needs to be locked down.
 */

it('maps running:healthy to ok', function () {
    expect(MonitorResourceAggregator::severityOf('running:healthy'))->toBe('ok');
});

it('maps running:unknown to ok (no healthcheck defined is not a problem)', function () {
    expect(MonitorResourceAggregator::severityOf('running:unknown'))->toBe('ok');
});

it('maps exited to ok (intentional stop is not an alert)', function () {
    expect(MonitorResourceAggregator::severityOf('exited'))->toBe('ok');
});

it('maps degraded:unhealthy to critical', function () {
    expect(MonitorResourceAggregator::severityOf('degraded:unhealthy'))->toBe('critical');
});

it('maps restarting:unknown to critical (crash loop)', function () {
    expect(MonitorResourceAggregator::severityOf('restarting:unknown'))->toBe('critical');
});

it('maps dead to critical', function () {
    expect(MonitorResourceAggregator::severityOf('dead'))->toBe('critical');
});

it('maps running:unhealthy to critical (healthcheck actively failing)', function () {
    expect(MonitorResourceAggregator::severityOf('running:unhealthy'))->toBe('critical');
});

it('maps starting:unknown to warning (transitional state)', function () {
    expect(MonitorResourceAggregator::severityOf('starting:unknown'))->toBe('warning');
});

it('maps paused:unknown to warning', function () {
    expect(MonitorResourceAggregator::severityOf('paused:unknown'))->toBe('warning');
});

it('defaults unknown strings to ok so noisy edge cases do not flood the alerts bell', function () {
    expect(MonitorResourceAggregator::severityOf(''))->toBe('ok');
    expect(MonitorResourceAggregator::severityOf('something-weird'))->toBe('ok');
});
