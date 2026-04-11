<?php

use App\Services\MonitorMetricsCollector;

/**
 * Tests for the private parsing helpers inside
 * MonitorMetricsCollector. We cannot round-trip the whole
 * gather() method without a real SSH target, but the parser is
 * where the subtle bugs live (empty keys, stray whitespace,
 * commas in load averages that break floatval, etc.) so it is
 * worth locking down with reflection.
 */

beforeEach(function () {
    $this->collector = new MonitorMetricsCollector;
    $this->parse = function (string $raw) {
        $m = new ReflectionMethod(MonitorMetricsCollector::class, 'parseKeyValues');
        $m->setAccessible(true);

        return $m->invoke($this->collector, $raw);
    };
    $this->toFloat = function (mixed $v) {
        $m = new ReflectionMethod(MonitorMetricsCollector::class, 'toFloat');
        $m->setAccessible(true);

        return $m->invoke($this->collector, $v);
    };
    $this->toInt = function (mixed $v) {
        $m = new ReflectionMethod(MonitorMetricsCollector::class, 'toInt');
        $m->setAccessible(true);

        return $m->invoke($this->collector, $v);
    };
});

it('parses a well-formed key=value blob', function () {
    $raw = "CPU_PCT=23.5\nMEM_PCT=67.8\nUPTIME=up 12 days";
    $result = ($this->parse)($raw);
    expect($result)->toBe([
        'CPU_PCT' => '23.5',
        'MEM_PCT' => '67.8',
        'UPTIME' => 'up 12 days',
    ]);
});

it('ignores blank lines and lines without equals', function () {
    $raw = "CPU_PCT=10\n\nnot-a-kv-pair\nMEM_PCT=20\n";
    $result = ($this->parse)($raw);
    expect($result)->toBe([
        'CPU_PCT' => '10',
        'MEM_PCT' => '20',
    ]);
});

it('trims whitespace around keys and values', function () {
    $raw = "  CPU_PCT = 42.0  \n  MEM_PCT  =  55  ";
    $result = ($this->parse)($raw);
    expect($result)->toBe([
        'CPU_PCT' => '42.0',
        'MEM_PCT' => '55',
    ]);
});

it('keeps the first = and puts the rest into the value (for things like LOAD=0.42, 0.15)', function () {
    $raw = 'DISK_HUMAN=2.1G / 8.0G';
    $result = ($this->parse)($raw);
    expect($result)->toBe(['DISK_HUMAN' => '2.1G / 8.0G']);
});

it('returns an empty array for a totally empty input', function () {
    expect(($this->parse)(''))->toBe([]);
    expect(($this->parse)("\n\n\n"))->toBe([]);
});

it('toFloat rounds to one decimal place', function () {
    expect(($this->toFloat)('23.456'))->toBe(23.5);
    expect(($this->toFloat)('0'))->toBe(0.0);
    expect(($this->toFloat)('100'))->toBe(100.0);
});

it('toFloat accepts comma as decimal separator (european locales)', function () {
    expect(($this->toFloat)('23,5'))->toBe(23.5);
});

it('toFloat returns null for non-numeric input instead of throwing', function () {
    expect(($this->toFloat)('not-a-number'))->toBeNull();
    expect(($this->toFloat)(null))->toBeNull();
    expect(($this->toFloat)(''))->toBeNull();
});

it('toInt casts numeric strings to int', function () {
    expect(($this->toInt)('42'))->toBe(42);
    expect(($this->toInt)('0'))->toBe(0);
});

it('toInt returns null for non-numeric input', function () {
    expect(($this->toInt)('abc'))->toBeNull();
    expect(($this->toInt)(null))->toBeNull();
    expect(($this->toInt)(''))->toBeNull();
});

it('cacheKeyFor is deterministic per server id', function () {
    $server = new \App\Models\Server;
    $server->id = 7;
    expect($this->collector->cacheKeyFor($server))->toBe('monitor:server:7:snapshot');
});
