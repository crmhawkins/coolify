<?php

use App\Services\FileExplorerCompressionTaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->service = new FileExplorerCompressionTaskService;
});

it('builds a deterministic cache key per team', function () {
    expect($this->service->cacheKey(7))->toBe('file-explorer-compression-tasks:7');
    expect($this->service->cacheKey('42'))->toBe('file-explorer-compression-tasks:42');
});

it('keeps running tasks regardless of how old they are', function () {
    $tasks = [
        [
            'id' => 'a',
            'status' => 'running',
            'created_at' => now()->subHour()->toDateTimeString(),
        ],
    ];

    expect($this->service->pruneStale($tasks))->toHaveCount(1);
});

it('keeps recently finished tasks within the stale window', function () {
    $tasks = [
        [
            'id' => 'a',
            'status' => 'completed',
            'finished_at' => now()->subSeconds(60)->toDateTimeString(),
        ],
        [
            'id' => 'b',
            'status' => 'failed',
            'finished_at' => now()->subSeconds(120)->toDateTimeString(),
        ],
    ];

    expect($this->service->pruneStale($tasks))->toHaveCount(2);
});

it('removes finished tasks older than the stale window', function () {
    $tasks = [
        [
            'id' => 'old-completed',
            'status' => 'completed',
            'finished_at' => now()->subMinutes(10)->toDateTimeString(),
        ],
        [
            'id' => 'old-failed',
            'status' => 'failed',
            'finished_at' => now()->subMinutes(15)->toDateTimeString(),
        ],
        [
            'id' => 'fresh',
            'status' => 'completed',
            'finished_at' => now()->subSeconds(30)->toDateTimeString(),
        ],
    ];

    $result = $this->service->pruneStale($tasks);

    expect($result)->toHaveCount(1);
    expect($result[0]['id'])->toBe('fresh');
});

it('keeps finished tasks that have no finished_at field at all', function () {
    // Backwards-compat: legacy cached tasks transitioned by older
    // code paths may not carry a finished_at timestamp. We must not
    // wipe them on the first poll, only future runs that go through
    // markFinished() will tag them.
    $tasks = [
        [
            'id' => 'legacy',
            'status' => 'completed',
            'created_at' => now()->subDay()->toDateTimeString(),
        ],
    ];

    expect($this->service->pruneStale($tasks))->toHaveCount(1);
});

it('keeps finished tasks with an unparseable finished_at', function () {
    $tasks = [
        [
            'id' => 'broken',
            'status' => 'failed',
            'finished_at' => 'not-a-date',
        ],
    ];

    expect($this->service->pruneStale($tasks))->toHaveCount(1);
});

it('roundtrips the task list through the cache via loadFor and saveFor', function () {
    Cache::flush();

    $tasks = [
        ['id' => 'x', 'status' => 'running'],
        ['id' => 'y', 'status' => 'completed', 'finished_at' => now()->toDateTimeString()],
    ];

    $this->service->saveFor(99, $tasks);

    $loaded = $this->service->loadFor(99);

    expect($loaded)->toHaveCount(2);
    expect($loaded[0]['id'])->toBe('x');
    expect($loaded[1]['id'])->toBe('y');
});

it('returns an empty array when no tasks are cached for the team', function () {
    Cache::flush();

    expect($this->service->loadFor(12345))->toBe([]);
});

it('discards non-array entries when pruning', function () {
    $tasks = [
        ['id' => 'real', 'status' => 'running'],
        'garbage-string',
        null,
    ];

    $result = $this->service->pruneStale($tasks);

    expect($result)->toHaveCount(1);
    expect($result[0]['id'])->toBe('real');
});
