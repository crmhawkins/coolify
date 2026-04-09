<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class LaravelCron extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public $applications;

    public array $laravelContainers = [];

    public ?int $selectedContainerForCron = null;

    public bool $isLoadingCron = false;

    public bool $isSchedulerEnabled = false;

    public string $schedulerStatus = '';

    public string $schedulerOutput = '';

    public bool $isLoadingScheduleList = false;

    /** @var array<int, array{command: string, expression: string, next_due: string, last_run: string, description: string, status: string, status_label: string, status_output: string, status_source: string, status_at: string}> */
    public array $scheduledTasks = [];

    /**
     * Index of tasks whose error panel is expanded. Keyed by task index.
     *
     * @var array<int, bool>
     */
    public array $expandedErrors = [];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        // Team scoping: see LaravelManager::mount() for the rationale.
        $this->service = Service::ownedByCurrentTeam()
            ->whereUuid(request()->route('service_uuid'))
            ->firstOrFail();
        $this->authorize('view', $this->service);
        $this->applications = $this->service->applications->sort();
        $this->detectLaravelContainers();

        // Cron UI should focus only on Laravel containers (no phpMyAdmin).
        // Default to the first running Laravel container if available.
        if ($this->selectedContainerForCron === null && ! empty($this->laravelContainers)) {
            $this->selectedContainerForCron = (int) ($this->laravelContainers[0]['id'] ?? null);
        }

        if ($this->selectedContainerForCron) {
            $this->loadCronData();
        }
    }

    public function detectLaravelContainers(): void
    {
        $this->laravelContainers = [];

        foreach ($this->applications as $application) {
            if (! $this->isLaravelContainer($application)) {
                continue;
            }

            $containerName = $application->name.'-'.$this->service->uuid;
            $this->laravelContainers[] = [
                'id' => $application->id,
                'name' => $application->name,
                'container_name' => $containerName,
                'status' => $application->status,
                'application' => $application,
            ];
        }
    }

    public function isLaravelContainer($application): bool
    {
        // Primary heuristic (cheap): image/env suggests Laravel.
        $image = strtolower($application->image ?? '');
        $looksLikeLaravel = str_contains($image, 'laravel') || str_contains($image, 'php');

        $envVars = $application->environment_variables()->get();
        foreach ($envVars as $envVar) {
            $key = strtoupper($envVar->key ?? '');
            if (str_contains($key, 'LARAVEL') || str_contains($key, 'APP_KEY') || str_contains($key, 'APP_ENV')) {
                $looksLikeLaravel = true;
                break;
            }
        }

        if (! $looksLikeLaravel) {
            return false;
        }

        // Strong check: artisan must exist in the container.
        if (! str($application->status)->contains('running')) {
            return false;
        }

        $server = $application->service->server;
        $containerName = $application->name.'-'.$this->service->uuid;
        $escapedContainer = escapeshellarg($containerName);

        $command = "docker exec {$escapedContainer} sh -c 'test -f /var/www/html/artisan && echo found || echo notfound'";
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        $output = trim(instant_remote_process([$command], $server, false) ?? '');

        return $output === 'found';
    }

    private function getSelectedContainerApplicationContext(): ?array
    {
        if (! $this->selectedContainerForCron) {
            return null;
        }

        $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
        if (! $container) {
            return null;
        }

        $application = $container['application'] ?? $this->applications->find($container['id']);
        if (! $application || ! str($application->status)->contains('running')) {
            return null;
        }

        $server = $application->service->server;
        $escapedContainer = escapeshellarg($container['container_name']);

        return [
            'container' => $container,
            'application' => $application,
            'server' => $server,
            'escapedContainer' => $escapedContainer,
        ];
    }

    public function loadCronData(): void
    {
        $this->checkSchedulerStatus();
        $this->loadScheduleList();
    }

    public function checkSchedulerStatus(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        $this->isLoadingCron = true;
        $this->schedulerStatus = '';
        $this->schedulerOutput = '';

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $escapedContainer = escapeshellarg($container['container_name']);

            $checkCommand = "docker exec {$escapedContainer} sh -c 'ps aux | grep -E \"schedule:(run|work)\" | grep -v grep || echo notfound'";
            if ($server->isNonRoot()) {
                $checkCommand = "sudo {$checkCommand}";
            }
            $processCheck = trim(instant_remote_process([$checkCommand], $server, false) ?? '');

            $supervisorCommand = "docker exec {$escapedContainer} sh -c 'supervisorctl status scheduler 2>/dev/null || echo notfound'";
            if ($server->isNonRoot()) {
                $supervisorCommand = "sudo {$supervisorCommand}";
            }
            $supervisorStatus = trim(instant_remote_process([$supervisorCommand], $server, false) ?? '');

            // "notfound" is our own sentinel for "supervisorctl binary is
            // missing in the container" — never leak it to the UI. Collapse
            // it to an empty string so the fallback phrasing kicks in.
            if ($supervisorStatus === 'notfound') {
                $supervisorStatus = '';
            }

            if ($processCheck !== 'notfound' || str_contains($supervisorStatus, 'RUNNING')) {
                $this->isSchedulerEnabled = true;
                $this->schedulerStatus = 'Running';
                $this->schedulerOutput = $supervisorStatus !== ''
                    ? $supervisorStatus
                    : '';
            } else {
                $this->isSchedulerEnabled = false;
                $this->schedulerStatus = 'Stopped';
                $this->schedulerOutput = $supervisorStatus !== ''
                    ? $supervisorStatus
                    : '';
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error checking scheduler status: '.$e->getMessage());
        } finally {
            $this->isLoadingCron = false;
        }
    }

    public function loadScheduleList(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        $this->isLoadingScheduleList = true;
        $this->scheduledTasks = [];

        try {
            $context = $this->getSelectedContainerApplicationContext();
            if (! $context) {
                $this->dispatch('error', 'Container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            // Prefer the framework output from the deployed Laravel app.
            $jsonCommand = "docker exec {$escapedContainer} sh -lc "
                .escapeshellarg(
                    "cd /var/www/html && php artisan optimize:clear >/dev/null 2>&1 || true; "
                    ."php artisan config:clear >/dev/null 2>&1 || true; "
                    ."php artisan schedule:list --format=json --no-interaction"
                );
            $rawJson = '';
            if ($server->isNonRoot()) {
                $jsonCommand = "sudo {$jsonCommand}";
            }
            $rawJson = (string) (instant_remote_process([$jsonCommand], $server, false) ?? '');
            $rawJson = trim($rawJson);
            $parsed = $this->parseScheduleListOutput($rawJson);

            if ($parsed === []) {
                // Some Laravel versions use --json instead.
                $jsonCommand = "docker exec {$escapedContainer} sh -lc "
                    .escapeshellarg(
                        "cd /var/www/html && php artisan schedule:list --json --no-interaction"
                    );
                if ($server->isNonRoot()) {
                    $jsonCommand = "sudo {$jsonCommand}";
                }
                $rawJson = (string) (instant_remote_process([$jsonCommand], $server, false) ?? '');
                $rawJson = trim($rawJson);
                $parsed = $this->parseScheduleListOutput($rawJson);
            }

            if ($parsed !== []) {
                $this->scheduledTasks = $parsed;

                return;
            }

            $plainCommand = "docker exec {$escapedContainer} sh -lc "
                .escapeshellarg(
                    "cd /var/www/html && php artisan schedule:list --no-interaction"
                );
            if ($server->isNonRoot()) {
                $plainCommand = "sudo {$plainCommand}";
            }

            $rawPlain = (string) (instant_remote_process([$plainCommand], $server, false) ?? '');
            $rawPlain = trim($rawPlain);

            $this->scheduledTasks = $this->parseScheduleListOutput($rawPlain);
            if ($this->scheduledTasks !== []) {
                return;
            }

            // Source-level fallback. We grep for both the static
            // Schedule::command(...) invocation style and the
            // $schedule->command(...) / $schedule->job(...) style used in the
            // classic app/Console/Kernel.php schedule() method. The filename
            // prefix in grep output lets us reconstruct the origin column.
            $sourceCommand = "docker exec {$escapedContainer} sh -lc "
                .escapeshellarg(
                    "cd /var/www/html && "
                    ."(grep -RInE \"(Schedule::|\\\\\\\$schedule->)(command|call|exec|job)\\(\" routes app/Console 2>/dev/null || true)"
                );
            if ($server->isNonRoot()) {
                $sourceCommand = "sudo {$sourceCommand}";
            }

            $rawSource = trim((string) (instant_remote_process([$sourceCommand], $server, false) ?? ''));
            $this->scheduledTasks = $this->parseScheduleSourceOutput($rawSource);

            if ($this->scheduledTasks !== []) {
                $this->schedulerOutput = "Showing schedule definitions detected in project source because `php artisan schedule:list` returned no parseable tasks.\n\n".$rawSource;
            } elseif ($rawPlain !== '') {
                $this->schedulerOutput = $rawPlain;
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error loading schedule list: '.$e->getMessage());
        } finally {
            $this->isLoadingScheduleList = false;
            // Enrich the parsed tasks with manual-run statuses (from cache)
            // and scheduler-run warnings (from laravel.log). Running this in
            // `finally` guarantees it executes after every early-return path
            // in the try block without duplicating the call at each site.
            $this->attachTaskStatuses();
        }
    }

    /**
     * @return array<int, array{command: string, expression: string, next_due: string, last_run: string, description: string}>
     */
    private function parseScheduleListOutput(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $raw = $this->sanitizeScheduleCommandOutput($raw);
        if ($raw === '') {
            return [];
        }

        // If json is available, prefer it. Laravel supports `--format=json` in some versions.
        $decoded = null;
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
        }

        if (is_array($decoded)) {
            $list = $decoded['tasks'] ?? $decoded['data'] ?? $decoded;
            if (is_array($list)) {
                $result = [];
                foreach ($list as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $result[] = [
                        'command' => (string) ($item['command'] ?? ''),
                        'expression' => (string) ($item['expression'] ?? ''),
                        'next_due' => (string) ($item['next_due'] ?? $item['nextDue'] ?? ''),
                        'last_run' => (string) ($item['last_run'] ?? $item['lastRun'] ?? ''),
                        'description' => (string) ($item['description'] ?? ''),
                    ];
                }

                return $result;
            }
        }

        // Modern Laravel plain-text schedule:list format. Output lines look
        // like:
        //   *    *    * * *   php artisan reservas:check-overlaps   Next Due: en 29 segundos
        //   0    8    1 * *   php artisan vacacioner:add ..........  Next Due: en 3 semanas
        //   *    *    * * *   Closure at: app/Console/Kernel.php:77  Next Due: en 29 segundos
        //
        // Columns are: <5 cron fields> <command or "Closure at: file:line">
        // <padding dots> Next Due: <relative time>. We capture them with a
        // single regex and clean the dot padding in PHP.
        $modernResult = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (! preg_match(
                '/^\s*(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.+?)\s+Next Due:\s*(.+?)\s*$/',
                $line,
                $m
            )) {
                continue;
            }

            $expression = trim("{$m[1]} {$m[2]} {$m[3]} {$m[4]} {$m[5]}");
            // Strip trailing dot padding Laravel inserts for visual alignment.
            $command = rtrim(trim((string) $m[6]), '. ');
            $nextDue = trim((string) $m[7]);

            // Skip the header line if `schedule:list` decides to emit one.
            if (stripos($command, 'command') !== false && stripos($expression, 'expression') !== false) {
                continue;
            }

            $description = '';
            // If the command row is actually a `Closure at: path:line` marker,
            // promote the path to the description so the card shows the origin
            // and keep the command column as just "Closure".
            if (preg_match('/^Closure at:\s*(.+)$/i', $command, $cm)) {
                $description = trim($cm[1]);
                $command = 'Closure';
            }

            $modernResult[] = [
                'command' => $command,
                'expression' => $expression,
                'next_due' => $nextDue,
                'last_run' => '',
                'description' => $description,
            ];
        }

        if ($modernResult !== []) {
            return $modernResult;
        }

        // Plain output fallback: Symfony table usually contains `|` separators.
        $lines = preg_split('/\r?\n/', $raw);
        $header = null;
        $headerIndex = [];
        $rows = [];
        $columnCount = null;

        foreach ($lines as $line) {
            if (strpos($line, '|') === false) {
                continue;
            }

            $trim = trim($line);
            if ($trim === '' || preg_match('/^\+[-+]+\+$/', $trim) === 1) {
                continue;
            }

            // Tokenize `| col | col |`
            $parts = array_map('trim', explode('|', trim($trim, "| ")));
            $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

            if ($columnCount === null) {
                $columnCount = count($parts);
            }

            if ($header === null) {
                $candidate = strtolower(implode(' ', $parts));
                if (str_contains($candidate, 'command') && str_contains($candidate, 'expression')) {
                    $header = $parts;
                    foreach ($header as $i => $name) {
                        $headerIndex[strtolower($name)] = $i;
                    }
                    continue;
                }
            } else {
                if ($columnCount !== count($parts)) {
                    continue;
                }

                if (count($parts) < 2) {
                    continue;
                }

                $rows[] = $parts;
            }
        }

        if ($header === null || $rows === []) {
            return [];
        }

        $result = [];
        foreach ($rows as $cols) {
            $command = '';
            foreach (['command', 'action'] as $name) {
                if (array_key_exists(strtolower($name), $headerIndex)) {
                    $command = (string) ($cols[$headerIndex[strtolower($name)]] ?? '');
                }
            }

            $expression = (string) ($cols[$headerIndex['expression'] ?? -1] ?? '');
            $nextDue = (string) ($cols[$headerIndex['next due'] ?? $headerIndex['next_due'] ?? -1] ?? '');
            $lastRun = (string) ($cols[$headerIndex['last run'] ?? $headerIndex['last_run'] ?? -1] ?? '');
            $description = (string) ($cols[$headerIndex['description'] ?? $headerIndex['desc'] ?? -1] ?? '');

            if ($command !== '') {
                $result[] = [
                    'command' => $command,
                    'expression' => $expression,
                    'next_due' => $nextDue,
                    'last_run' => $lastRun,
                    'description' => $description,
                ];
            }
        }

        return $result;
    }

    /**
     * Strips everything that will confuse the JSON / plain-text parsers
     * downstream from the raw `schedule:list` output:
     *   - ANSI color escape sequences (Laravel 11+ emits them by default)
     *   - PHP deprecation / notice / warning / fatal / strict lines and
     *     their continuation frames ("in /path/to/file.php on line N")
     *   - Xdebug callstack headers
     *   - Known noisy third-party vendor warnings we have already seen
     *     leak into this output in the wild
     *   - Everything BEFORE the first '{' or '[' when the output looks
     *     like JSON (Laravel sometimes prints a banner before the data)
     */
    private function sanitizeScheduleCommandOutput(string $raw): string
    {
        $raw = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $raw) ?? $raw;

        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $cleaned = [];

        // Regex catches every PHP runtime notice prefix, with or without
        // the leading "PHP " that cli sapi adds. Anchored at start-of-
        // trimmed-line so a legit task command that happens to contain
        // the word "warning" is not dropped.
        $noisePrefixRegex = '/^(PHP\s+)?(Deprecated|Notice|Warning|Strict Standards|Fatal error|Parse error|Recoverable fatal error):/i';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $cleaned[] = $line;

                continue;
            }

            if (preg_match($noisePrefixRegex, $trimmed)) {
                continue;
            }

            // "in /path/to/file.php on line N" continuation frames that
            // Xdebug / php-fpm append after a warning.
            if (preg_match('/\s+in\s+\/[^ ]+\.php\s+on\s+line\s+\d+\s*$/', $trimmed)) {
                continue;
            }

            // Xdebug stack frame header.
            if (str_starts_with($trimmed, 'Stack trace:')) {
                continue;
            }

            if (str_contains($trimmed, '/vendor/serpapi/google-search-results-php/restclient.php')) {
                continue;
            }

            $cleaned[] = $line;
        }

        $joined = trim(implode("\n", $cleaned));

        // If the cleaned output looks like JSON-with-a-prefix (anything
        // before the first { or [), drop the prefix so json_decode()
        // downstream succeeds. This handles the "Laravel prints a
        // banner before the JSON" case that the old parser fell off
        // when silently going to the source-grep fallback.
        if ($joined !== '' && preg_match('/[\{\[]/', $joined, $_, PREG_OFFSET_CAPTURE)) {
            $firstBrace = strpos($joined, '{');
            $firstBracket = strpos($joined, '[');
            $candidates = array_filter([$firstBrace, $firstBracket], fn ($p) => $p !== false);
            if ($candidates !== []) {
                $cut = min($candidates);
                if ($cut > 0) {
                    $joined = substr($joined, $cut);
                }
            }
        }

        return $joined;
    }

    /**
     * Parses the raw grep output from the source-level fallback into a
     * structured task list. Each match of a Schedule::command(...) or
     * $schedule->command(...) invocation becomes a row with the inferred
     * artisan command name in `command` and the cron expression / interval
     * shortcut (everyMinute / dailyAt('08:00') / cron('* * * * *') / etc)
     * in `expression`. If we cannot find either on the same line we still
     * produce a row so the user at least sees that a schedule entry was
     * detected, along with the file and line number in `description`.
     *
     * @return array<int, array{command: string, expression: string, next_due: string, last_run: string, description: string}>
     */
    private function parseScheduleSourceOutput(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $tasks = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // grep -In prints "path/to/file:line:content".
            $parts = preg_split('/:/', $line, 3);
            if (count($parts) < 3) {
                continue;
            }
            $location = "{$parts[0]}:{$parts[1]}";
            $content = trim((string) $parts[2]);

            // Only keep lines that actually invoke command()/call()/exec()/job().
            // The grep already filters for this but defensive programming
            // makes the parser robust to noise like comments that happen to
            // contain the word "Schedule::".
            if (! preg_match('/(?:Schedule::|\$schedule->)(command|call|exec|job)\s*\(\s*(.*)$/', $content, $kindMatch)) {
                continue;
            }

            // Extract the first string argument to command()/call()/etc as
            // the task name. Handles both single and double quoted strings.
            $commandName = '';
            if (preg_match("/(?:Schedule::|\\\$schedule->)(?:command|call|exec|job)\s*\(\s*(['\"])([^'\"]+)\\1/", $content, $nameMatch)) {
                $commandName = $nameMatch[2];
            }

            // Infer the schedule expression from common helper calls on the
            // same line. Laravel devs often chain everything on one line,
            // so this catches the majority of real schedules.
            $expression = '';
            if (preg_match('/->cron\(\s*([\'"])([^\'"]+)\1/', $content, $cronMatch)) {
                $expression = $cronMatch[2];
            } elseif (preg_match('/->(everyMinute|everyTwoMinutes|everyThreeMinutes|everyFourMinutes|everyFiveMinutes|everyTenMinutes|everyFifteenMinutes|everyThirtyMinutes|hourly|hourlyAt|daily|dailyAt|twiceDaily|weekly|weeklyOn|monthly|monthlyOn|quarterly|yearly|yearlyOn|everySecond|everyTwoSeconds|everyFiveSeconds|everyTenSeconds|everyFifteenSeconds|everyThirtySeconds)\(([^)]*)\)/', $content, $helperMatch)) {
                $helper = $helperMatch[1];
                $args = trim($helperMatch[2]);
                $expression = $args === '' ? $helper : $helper.'('.$args.')';
            }

            $tasks[] = [
                'command' => $commandName !== '' ? $commandName : $content,
                'expression' => $expression !== '' ? $expression : 'Defined in source',
                'next_due' => 'Resolve via artisan schedule:list',
                'last_run' => '',
                'description' => $location,
            ];
        }

        return collect($tasks)
            ->unique(fn (array $task) => $task['command'].'|'.$task['description'])
            ->values()
            ->all();
    }

    public function executeTaskNow(int $index): void
    {
        if (! isset($this->scheduledTasks[$index])) {
            $this->dispatch('error', 'Task not found.');

            return;
        }

        $taskCommand = trim((string) data_get($this->scheduledTasks[$index], 'command', ''));
        if ($taskCommand === '') {
            $this->dispatch('error', 'Task command is empty.');

            return;
        }

        // Closure tasks cannot be re-executed from the UI because we do not
        // know what PHP code was registered inside them — artisan has no
        // sub-command for "run this specific closure from the Kernel".
        if (str_starts_with($taskCommand, 'Closure')) {
            $this->dispatch('error', 'No se pueden ejecutar manualmente las tareas Closure definidas inline en Kernel.php.');

            return;
        }

        $context = $this->getSelectedContainerApplicationContext();
        if (! $context) {
            $this->dispatch('error', 'Container not found or not running.');

            return;
        }

        $this->schedulerOutput = '';

        $result = $this->runArtisanTaskForCard($index, $taskCommand, $context);

        if ($result['status'] === 'success') {
            $this->dispatch('success', 'Task executed successfully.');
        } else {
            // Auto-expand the error panel on failure so the user sees it
            // without having to click.
            $this->expandedErrors[$index] = true;
            $message = $result['exit_code'] !== null
                ? "Task finished with exit code {$result['exit_code']}."
                : 'Error executing task.';
            $this->dispatch('error', $message);
        }
    }

    /**
     * Bulk-run every non-Closure task in $this->scheduledTasks and persist
     * each result via persistTaskStatus so the card grid reflects the
     * outcome on next render. Continues on failures so one broken command
     * does not abort the loop. Emits a single summary event at the end.
     */
    public function executeAllTasksNow(): void
    {
        if ($this->scheduledTasks === []) {
            $this->dispatch('error', 'No hay tareas que ejecutar.');

            return;
        }

        $context = $this->getSelectedContainerApplicationContext();
        if (! $context) {
            $this->dispatch('error', 'Container not found or not running.');

            return;
        }

        $this->schedulerOutput = '';

        $total = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->scheduledTasks as $index => $task) {
            $taskCommand = trim((string) data_get($task, 'command', ''));

            if ($taskCommand === '' || str_starts_with($taskCommand, 'Closure')) {
                $skipped++;

                continue;
            }

            $total++;
            $result = $this->runArtisanTaskForCard($index, $taskCommand, $context);

            if ($result['status'] === 'success') {
                $success++;
            } else {
                $failed++;
            }
        }

        $parts = ["Ejecutadas {$total} tareas"];
        if ($success > 0) {
            $parts[] = "{$success} correctas";
        }
        if ($failed > 0) {
            $parts[] = "{$failed} con fallo";
        }
        if ($skipped > 0) {
            $parts[] = "{$skipped} Closure omitidas";
        }
        $summary = implode(' · ', $parts).'.';

        if ($failed === 0 && $total > 0) {
            $this->dispatch('success', $summary);
        } elseif ($failed > 0) {
            $this->dispatch('error', $summary);
        } else {
            $this->dispatch('warning', $summary);
        }
    }

    /**
     * Runs a single artisan task inside the selected Laravel container and
     * persists its outcome in cache + in-memory scheduledTasks via
     * persistTaskStatus(). Returns an array with the captured status, the
     * artisan exit code, and the trimmed stdout/stderr so the caller can
     * decide what user-facing event to dispatch.
     *
     * Used by both executeTaskNow() (single-task click) and
     * executeAllTasksNow() (bulk run from the header button).
     *
     * @param  array{container: array<string, mixed>, application: mixed, server: mixed, escapedContainer: string}  $context
     * @return array{status: string, exit_code: ?int, output: string}
     */
    private function runArtisanTaskForCard(int $index, string $taskCommand, array $context): array
    {
        $server = $context['server'];
        $escapedContainer = $context['escapedContainer'];

        $tokens = preg_split('/\s+/', $taskCommand) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        // "php artisan xxx" → drop the leading "php artisan" so we don't
        // invoke php twice when we build the docker exec command below.
        if (count($tokens) >= 2 && strtolower($tokens[0]) === 'php' && strtolower($tokens[1]) === 'artisan') {
            $tokens = array_slice($tokens, 2);
        }

        foreach ($tokens as $token) {
            if (preg_match('/[;&|`$<>\\\\]/', (string) $token)) {
                $this->persistTaskStatus($index, [
                    'command' => $taskCommand,
                    'status' => 'error',
                    'status_label' => 'Falló',
                    'status_output' => 'Invalid task command (contains unsafe shell characters).',
                    'status_source' => 'manual',
                    'status_at' => now()->format('Y-m-d H:i:s'),
                ]);

                return ['status' => 'error', 'exit_code' => null, 'output' => 'Invalid task command.'];
            }
        }

        try {
            // Execute wrapped in `... ; echo __EXIT__=$?` so we can capture
            // the artisan exit code even when instant_remote_process does
            // not expose it directly.
            $artisanArgs = implode(' ', array_map('escapeshellarg', $tokens));
            $innerScript = "php /var/www/html/artisan {$artisanArgs} 2>&1; echo \"__EXIT__=\$?\"";
            $command = "docker exec {$escapedContainer} sh -lc ".escapeshellarg($innerScript);
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $rawOutput = (string) (instant_remote_process([$command], $server, false) ?? '');

            $exitCode = null;
            if (preg_match('/__EXIT__=(\d+)\s*$/', $rawOutput, $m)) {
                $exitCode = (int) $m[1];
                $rawOutput = preg_replace('/__EXIT__=\d+\s*$/', '', $rawOutput) ?: $rawOutput;
            }
            $rawOutput = rtrim($rawOutput);

            $ok = $exitCode === 0;
            $status = $ok ? 'success' : 'error';
            $label = $ok ? 'Ejecutó correctamente' : 'Falló';

            $this->persistTaskStatus($index, [
                'command' => $taskCommand,
                'status' => $status,
                'status_label' => $label,
                'status_output' => $rawOutput,
                'status_source' => 'manual',
                'status_at' => now()->format('Y-m-d H:i:s'),
            ]);

            return ['status' => $status, 'exit_code' => $exitCode, 'output' => $rawOutput];
        } catch (\Throwable $e) {
            $this->persistTaskStatus($index, [
                'command' => $taskCommand,
                'status' => 'error',
                'status_label' => 'Falló',
                'status_output' => $e->getMessage(),
                'status_source' => 'manual',
                'status_at' => now()->format('Y-m-d H:i:s'),
            ]);

            return ['status' => 'error', 'exit_code' => null, 'output' => $e->getMessage()];
        }
    }

    public function toggleScheduler(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $escapedContainer = escapeshellarg($container['container_name']);

            if ($this->isSchedulerEnabled) {
                $command = "docker exec {$escapedContainer} supervisorctl stop scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = false;
                $this->schedulerStatus = 'Stopped';
            } else {
                $command = "docker exec {$escapedContainer} supervisorctl start scheduler";
                if ($server->isNonRoot()) {
                    $command = "sudo {$command}";
                }
                instant_remote_process([$command], $server, false);
                $this->isSchedulerEnabled = true;
                $this->schedulerStatus = 'Running';
            }

            $this->checkSchedulerStatus();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Error toggling scheduler: '.$e->getMessage());
        }
    }

    public function runScheduler(): void
    {
        if (! $this->selectedContainerForCron) {
            return;
        }

        try {
            $container = collect($this->laravelContainers)->firstWhere('id', $this->selectedContainerForCron);
            if (! $container) {
                $this->dispatch('error', 'Container not found.');

                return;
            }

            $application = $container['application'] ?? $this->applications->find($container['id']);
            if (! $application || ! str($application->status)->contains('running')) {
                $this->dispatch('error', 'Container is not running.');

                return;
            }

            $server = $application->service->server;
            $escapedContainer = escapeshellarg($container['container_name']);

            $command = "docker exec {$escapedContainer} sh -lc "
                .escapeshellarg(
                    "cd /var/www/html && php artisan optimize:clear >/dev/null 2>&1 || true; "
                    ."php artisan config:clear >/dev/null 2>&1 || true; "
                    ."echo 'Scheduler execution context:'; "
                    .'echo "APP_ENV=${APP_ENV:-}"; '
                    .'echo "CACHE_STORE=${CACHE_STORE:-}"; '
                    .'echo "QUEUE_CONNECTION=${QUEUE_CONNECTION:-}"; '
                    ."php artisan schedule:run --verbose --no-interaction"
                );
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            $this->schedulerOutput = (string) (instant_remote_process([$command], $server, false) ?? '');
            $this->dispatch('success', 'Scheduler executed successfully.');
        } catch (\Throwable $e) {
            $this->schedulerOutput = $e->getMessage();
            $this->dispatch('error', 'Error running scheduler: '.$e->getMessage());
        }
    }

    /**
     * Toggles the expanded/collapsed state of the error panel for a given
     * task index. Called from the card's "Ver error" button in the view.
     */
    public function toggleErrorPanel(int $index): void
    {
        if (isset($this->expandedErrors[$index]) && $this->expandedErrors[$index]) {
            unset($this->expandedErrors[$index]);

            return;
        }

        $this->expandedErrors[$index] = true;
    }

    /**
     * Returns the cache key used to store per-container task status.
     * We key by service id + container name so each Laravel RootKit gets
     * its own bucket and statuses do not bleed between projects.
     */
    private function taskStatusCacheKey(?string $containerName = null): string
    {
        if ($containerName === null) {
            $context = $this->getSelectedContainerApplicationContext();
            $containerName = $context['container']['container_name'] ?? 'unknown';
        }

        return 'laravel-cron-task-status:'.$this->service->id.':'.$containerName;
    }

    /**
     * Stores the manual-run status for a single task in cache (24h TTL) and
     * updates the in-memory scheduledTasks array so the view reflects it
     * immediately on the next render cycle without a full reload.
     *
     * @param  array{command: string, status: string, status_label: string, status_output: string, status_source: string, status_at: string}  $payload
     */
    private function persistTaskStatus(int $index, array $payload): void
    {
        try {
            $key = $this->taskStatusCacheKey();
            $bucket = Cache::get($key, []);
            if (! is_array($bucket)) {
                $bucket = [];
            }

            // Key entries in the cache by the task command so re-runs of
            // the same command overwrite the previous entry instead of
            // piling up, and so a reload of the list picks them up
            // regardless of the array index in scheduledTasks.
            $bucket[$payload['command']] = $payload;

            Cache::put($key, $bucket, now()->addHours(24));
        } catch (\Throwable $e) {
            report($e);
        }

        if (isset($this->scheduledTasks[$index])) {
            $this->scheduledTasks[$index]['status'] = $payload['status'];
            $this->scheduledTasks[$index]['status_label'] = $payload['status_label'];
            $this->scheduledTasks[$index]['status_output'] = $payload['status_output'];
            $this->scheduledTasks[$index]['status_source'] = $payload['status_source'];
            $this->scheduledTasks[$index]['status_at'] = $payload['status_at'];
        }
    }

    /**
     * Attaches manual-run statuses from cache and scheduler-run warnings
     * from the Laravel log to the in-memory task list. Called from
     * loadScheduleList() once the tasks have been parsed so the two data
     * sources converge onto the same array the blade consumes.
     */
    private function attachTaskStatuses(): void
    {
        if ($this->scheduledTasks === []) {
            return;
        }

        // Step 1 — manual runs from cache (Option C of the hybrid plan).
        $cacheBucket = [];
        try {
            $cacheBucket = Cache::get($this->taskStatusCacheKey(), []);
            if (! is_array($cacheBucket)) {
                $cacheBucket = [];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Step 2 — recent scheduler-run errors from laravel.log (Option A).
        $logErrors = $this->scanLaravelLogForRecentErrors();

        foreach ($this->scheduledTasks as $i => $task) {
            $command = (string) ($task['command'] ?? '');

            // Default: no status known.
            $this->scheduledTasks[$i]['status'] = 'unknown';
            $this->scheduledTasks[$i]['status_label'] = '';
            $this->scheduledTasks[$i]['status_output'] = '';
            $this->scheduledTasks[$i]['status_source'] = '';
            $this->scheduledTasks[$i]['status_at'] = '';

            // Cache (manual run) takes precedence because it is deterministic.
            if (isset($cacheBucket[$command]) && is_array($cacheBucket[$command])) {
                $entry = $cacheBucket[$command];
                $this->scheduledTasks[$i]['status'] = (string) ($entry['status'] ?? 'unknown');
                $this->scheduledTasks[$i]['status_label'] = (string) ($entry['status_label'] ?? '');
                $this->scheduledTasks[$i]['status_output'] = (string) ($entry['status_output'] ?? '');
                $this->scheduledTasks[$i]['status_source'] = (string) ($entry['status_source'] ?? 'manual');
                $this->scheduledTasks[$i]['status_at'] = (string) ($entry['status_at'] ?? '');

                continue;
            }

            // Fallback — log grep. If the command name appears in an ERROR
            // line within the last 24h of laravel.log, flag it as a log
            // warning (amber). A clean log means nothing, so we leave
            // status='unknown' (the view will render nothing).
            $needle = $this->commandNameForLogMatching($command);
            if ($needle !== '' && isset($logErrors[$needle])) {
                $this->scheduledTasks[$i]['status'] = 'log-warning';
                $this->scheduledTasks[$i]['status_label'] = 'Error reciente en log';
                $this->scheduledTasks[$i]['status_output'] = (string) $logErrors[$needle]['excerpt'];
                $this->scheduledTasks[$i]['status_source'] = 'log';
                $this->scheduledTasks[$i]['status_at'] = (string) $logErrors[$needle]['when'];
            }
        }
    }

    /**
     * Extracts the bare artisan command name from a "command" field that
     * can look like "php artisan reservas:check-overlaps", "reservas:x",
     * or "Closure". Used to match against grep hits inside laravel.log.
     */
    private function commandNameForLogMatching(string $command): string
    {
        $command = trim($command);
        if ($command === '' || str_starts_with($command, 'Closure')) {
            return '';
        }

        $tokens = preg_split('/\s+/', $command) ?: [];
        if (count($tokens) >= 2 && strtolower($tokens[0]) === 'php' && strtolower($tokens[1]) === 'artisan') {
            return (string) ($tokens[2] ?? '');
        }

        return (string) ($tokens[0] ?? '');
    }

    /**
     * Greps storage/logs/laravel.log inside the selected container for
     * recent ERROR lines and returns a map keyed by the artisan command
     * name so attachTaskStatuses() can annotate each row.
     *
     * Runs `tail -n 2000` so we cap the IO cost at ~200 KB per reload
     * regardless of how big the log is.
     *
     * @return array<string, array{excerpt: string, when: string}>
     */
    private function scanLaravelLogForRecentErrors(): array
    {
        try {
            $context = $this->getSelectedContainerApplicationContext();
            if (! $context) {
                return [];
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];

            // tail + grep for local.ERROR lines. We keep the command and
            // the surrounding 2 lines for context. `|| true` so an empty
            // match does not propagate a non-zero exit.
            $script = "tail -n 2000 /var/www/html/storage/logs/laravel.log 2>/dev/null "
                ."| grep -E 'local\\.ERROR|production\\.ERROR|local\\.CRITICAL|production\\.CRITICAL' || true";
            $command = "docker exec {$escapedContainer} sh -lc ".escapeshellarg($script);
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $raw = (string) (instant_remote_process([$command], $server, false) ?? '');
            if (trim($raw) === '') {
                return [];
            }
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        // Build a lookup: artisan command name → last error line excerpt.
        // Log lines look like:
        //   [2026-04-09 08:13:22] local.ERROR: Command "reservas:check-overlaps" failed: ...
        //   [2026-04-09 08:12:01] local.ERROR: Something crashed in reservas:generar-token-dni ...
        $result = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $when = '';
            if (preg_match('/^\[([^\]]+)\]/', $line, $dm)) {
                $when = $dm[1];
            }

            // Try to extract the artisan command name that appears in the
            // line. We look for 3 patterns Laravel uses: Command "xxx",
            // command 'xxx', and bare xxx:yyy tokens.
            $candidates = [];
            if (preg_match_all('/Command\s+"([^"\s]+:[^"\s]+)"/', $line, $m)) {
                $candidates = array_merge($candidates, $m[1]);
            }
            if (preg_match_all("/command\\s+'([^'\\s]+:[^'\\s]+)'/", $line, $m)) {
                $candidates = array_merge($candidates, $m[1]);
            }
            if (preg_match_all('/\b([a-z][a-z0-9\-]*:[a-z][a-z0-9\-]*)\b/', $line, $m)) {
                $candidates = array_merge($candidates, $m[1]);
            }

            foreach (array_unique($candidates) as $name) {
                // Only keep the most recent (last) error per command.
                $result[$name] = [
                    'excerpt' => $line,
                    'when' => $when,
                ];
            }
        }

        return $result;
    }

    public function render()
    {
        return view('livewire.project.service.laravel-cron');
    }
}
