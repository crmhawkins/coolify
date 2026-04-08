<div>
    <x-slot:title>
        Laravel Cron | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Manager</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-artisan', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Artisan Commands</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-cron', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Cron</span></a>
        </div>

        {{-- Same lesson we learned on the Artisan page: NO overflow-x-hidden
             on the wrapper, use min-w-0 instead so the dropdown / card grid
             can expand without being clipped by an implicit overflow-y. --}}
        <div class="w-full min-w-0 flex flex-col gap-6">
            {{-- Page header with title, scheduler health and reload action --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold dark:text-white">Laravel Scheduler</h2>
                    <div class="subtitle">Tareas programadas del contenedor Laravel.</div>
                </div>
                <div class="flex items-center gap-3">
                    @if ($schedulerStatus === 'Running')
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                              style="background-color:rgba(34,197,94,0.15);color:#86efac;border:1px solid rgba(34,197,94,0.3);">
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color:#22c55e;"></span>
                            Scheduler activo
                        </span>
                    @elseif ($schedulerStatus === 'Stopped')
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                              style="background-color:rgba(239,68,68,0.15);color:#fca5a5;border:1px solid rgba(239,68,68,0.3);">
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color:#ef4444;"></span>
                            Scheduler detenido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                              style="background-color:rgba(245,158,11,0.15);color:#fcd34d;border:1px solid rgba(245,158,11,0.3);">
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color:#f59e0b;"></span>
                            Estado desconocido
                        </span>
                    @endif

                    {{-- Bulk run button: fires every non-Closure task in
                         sequence and updates each card's status badge via
                         the same cache pipeline used by "Ejecutar".
                         Disabled during the run AND during a normal
                         Recargar so the two actions never overlap.
                         Confirmation dialog avoids accidental mass runs. --}}
                    <button
                        type="button"
                        wire:click="executeAllTasksNow"
                        wire:confirm="Esto ejecutará todas las tareas del scheduler (excepto las Closure). ¿Continuar?"
                        wire:loading.attr="disabled"
                        wire:target="executeAllTasksNow,loadScheduleList"
                        class="rounded px-3 py-1.5 text-xs font-semibold transition-all disabled:opacity-60"
                        style="background-color:#8b5cf6;color:#ffffff;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                        onmouseover="this.style.backgroundColor='#7c3aed'"
                        onmouseout="this.style.backgroundColor='#8b5cf6'"
                    >
                        <span wire:loading.remove wire:target="executeAllTasksNow">Ejecutar todas</span>
                        <span wire:loading wire:target="executeAllTasksNow">Ejecutando…</span>
                    </button>

                    <x-forms.button
                        wire:click="loadScheduleList"
                        wire:loading.attr="disabled"
                        wire:target="loadScheduleList,executeAllTasksNow"
                    >
                        <span wire:loading.remove wire:target="loadScheduleList">Recargar</span>
                        <span wire:loading wire:target="loadScheduleList">Cargando…</span>
                    </x-forms.button>
                </div>
            </div>

            @if (empty($laravelContainers))
                <div class="rounded border border-coolgray-300 dark:border-coolgray-700 p-4 text-sm text-neutral-500">
                    No Laravel containers detected in this service.
                </div>
            @else
                @if ($isLoadingScheduleList)
                    <div class="rounded border border-dashed border-coolgray-300 dark:border-coolgray-600 px-4 py-10 text-center text-sm text-neutral-500">
                        Cargando schedule list…
                    </div>
                @elseif (empty($scheduledTasks))
                    <div class="rounded border border-dashed border-coolgray-300 dark:border-coolgray-600 px-4 py-10 text-center text-sm text-neutral-500">
                        No hay tareas programadas detectadas. Asegúrate de que el contenedor Laravel está corriendo y que la aplicación define schedules en <code>routes/console.php</code> o <code>app/Console/Kernel.php</code>.
                    </div>
                @else
                    <div class="text-xs text-neutral-500">
                        {{ count($scheduledTasks) }} {{ count($scheduledTasks) === 1 ? 'tarea programada' : 'tareas programadas' }}
                    </div>

                    {{-- Card grid: 1 column on mobile, 2 on md+, stays
                         readable and compact even when there are 15-20
                         tasks like in the Apartamentos project.

                         Palette: dark background (#18181b) to match the
                         rest of Coolify's dark theme, with near-white
                         text for the command name, purple accents on
                         the "Ejecutar" button and on the "Próxima"
                         badge, and translucent color swatches for the
                         other metadata. All colors are inlined so the
                         Coolify dark-mode cascade cannot hijack them. --}}
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($scheduledTasks as $taskIndex => $task)
                            <div
                                class="rounded-lg border shadow-lg"
                                style="background-color:#18181b;border-color:#27272a;color:#e4e4e7;"
                            >
                                {{-- Card header: command name + run button --}}
                                <div class="flex items-start justify-between gap-3 px-4 pt-3">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#a1a1aa;">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <span class="font-mono text-sm font-semibold truncate" style="color:#ffffff;" title="{{ $task['command'] }}">
                                            {{ $task['command'] ?: '—' }}
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="executeTaskNow({{ $taskIndex }})"
                                        wire:loading.attr="disabled"
                                        wire:target="executeTaskNow({{ $taskIndex }})"
                                        class="shrink-0 rounded px-2.5 py-1 text-xs font-semibold transition-all hover:opacity-90"
                                        style="background-color:#8b5cf6;color:#ffffff;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                                        onmouseover="this.style.backgroundColor='#7c3aed'"
                                        onmouseout="this.style.backgroundColor='#8b5cf6'"
                                    >
                                        <span wire:loading.remove wire:target="executeTaskNow({{ $taskIndex }})">Ejecutar</span>
                                        <span wire:loading wire:target="executeTaskNow({{ $taskIndex }})">…</span>
                                    </button>
                                </div>

                                {{-- Card body: badges for interval + next run + status --}}
                                <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                                    <span
                                        class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-mono"
                                        style="background-color:#27272a;color:#e4e4e7;border:1px solid #3f3f46;"
                                        title="Expresión cron"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        {{ $task['expression'] ?: '—' }}
                                    </span>
                                    @if (! empty($task['next_due']))
                                        <span
                                            class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs"
                                            style="background-color:rgba(139,92,246,0.15);color:#c4b5fd;border:1px solid rgba(139,92,246,0.3);"
                                            title="Próxima ejecución"
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                            </svg>
                                            Próxima: {{ $task['next_due'] }}
                                        </span>
                                    @endif

                                    {{-- Status badge (hybrid A+C plan):
                                         • success → green "Ejecutó correctamente"
                                         • error   → red "Falló" + "Ver error" toggle
                                         • log-warning → amber "Error reciente en log"
                                         • unknown → neutral "Sin datos" so every
                                           card shows a status column at a glance.
                                           This is the baseline state before any
                                           manual run or log-level error appears. --}}
                                    @php
                                        $taskStatus = $task['status'] ?? 'unknown';
                                        $statusLabel = $task['status_label'] ?? '';
                                        $statusAt = $task['status_at'] ?? '';
                                        $hasOutput = ! empty($task['status_output']);
                                    @endphp
                                    @if ($taskStatus === 'unknown' || $taskStatus === '')
                                        <span
                                            class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold"
                                            style="background-color:#27272a;color:#a1a1aa;border:1px solid #3f3f46;"
                                            title="Aún no se ha registrado ninguna ejecución manual ni se ha detectado error reciente en laravel.log. Pulsa 'Ejecutar' para registrar el estado."
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Sin datos
                                        </span>
                                    @elseif ($taskStatus === 'success')
                                        <span
                                            class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold"
                                            style="background-color:rgba(34,197,94,0.15);color:#86efac;border:1px solid rgba(34,197,94,0.3);"
                                            @if ($statusAt) title="{{ $statusAt }}" @endif
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                            {{ $statusLabel ?: 'Ejecutó correctamente' }}
                                        </span>
                                    @elseif ($taskStatus === 'error')
                                        <span
                                            class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold"
                                            style="background-color:rgba(239,68,68,0.15);color:#fca5a5;border:1px solid rgba(239,68,68,0.3);"
                                            @if ($statusAt) title="{{ $statusAt }}" @endif
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                            {{ $statusLabel ?: 'Falló' }}
                                        </span>
                                        @if ($hasOutput)
                                            <button
                                                type="button"
                                                wire:click="toggleErrorPanel({{ $taskIndex }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold transition-colors"
                                                style="background-color:#27272a;color:#fca5a5;border:1px solid #3f3f46;"
                                                onmouseover="this.style.backgroundColor='#3f3f46'"
                                                onmouseout="this.style.backgroundColor='#27272a'"
                                            >
                                                {{ ! empty($expandedErrors[$taskIndex]) ? 'Ocultar error' : 'Ver error' }}
                                            </button>
                                        @endif
                                    @elseif ($taskStatus === 'log-warning')
                                        <span
                                            class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold"
                                            style="background-color:rgba(245,158,11,0.15);color:#fcd34d;border:1px solid rgba(245,158,11,0.3);"
                                            @if ($statusAt) title="{{ $statusAt }}" @endif
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                            </svg>
                                            {{ $statusLabel ?: 'Error reciente en log' }}
                                        </span>
                                        @if ($hasOutput)
                                            <button
                                                type="button"
                                                wire:click="toggleErrorPanel({{ $taskIndex }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold transition-colors"
                                                style="background-color:#27272a;color:#fcd34d;border:1px solid #3f3f46;"
                                                onmouseover="this.style.backgroundColor='#3f3f46'"
                                                onmouseout="this.style.backgroundColor='#27272a'"
                                            >
                                                {{ ! empty($expandedErrors[$taskIndex]) ? 'Ocultar log' : 'Ver log' }}
                                            </button>
                                        @endif
                                    @endif

                                    @if (! empty($task['last_run']))
                                        <span
                                            class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs"
                                            style="background-color:rgba(34,197,94,0.15);color:#86efac;border:1px solid rgba(34,197,94,0.3);"
                                            title="Última ejecución"
                                        >
                                            Última: {{ $task['last_run'] }}
                                        </span>
                                    @endif
                                </div>

                                {{-- Collapsible error panel. Shown when the user
                                     clicks "Ver error" on a failed task or
                                     when executeTaskNow auto-expanded it. --}}
                                @if (! empty($expandedErrors[$taskIndex]) && ! empty($task['status_output']))
                                    <div
                                        class="border-t px-4 py-3"
                                        style="border-color:#27272a;"
                                    >
                                        <div class="mb-1 text-xs font-semibold" style="color:#a1a1aa;">
                                            Salida del comando
                                            @if (! empty($task['status_at']))
                                                · <span style="color:#71717a;">{{ $task['status_at'] }}</span>
                                            @endif
                                        </div>
                                        <pre
                                            class="whitespace-pre-wrap break-words rounded px-3 py-2 text-xs font-mono max-h-60 overflow-auto"
                                            style="background-color:#0a0a0a;color:#fca5a5;border:1px solid #27272a;"
                                        >{{ $task['status_output'] }}</pre>
                                    </div>
                                @endif

                                {{-- Card footer: origin / description if set --}}
                                @if (! empty($task['description']))
                                    <div
                                        class="border-t px-4 py-2 text-xs font-mono truncate"
                                        style="border-color:#27272a;color:#71717a;"
                                        title="{{ $task['description'] }}"
                                    >
                                        {{ $task['description'] }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Output panel: shown only when a task execution produces
                     something useful. We also explicitly filter out the
                     "notfound" sentinel that the supervisorctl probe emits
                     when the binary is missing from the container — the
                     PHP side already collapses it to empty, but keeping
                     this second guard in the view means old cached
                     versions of LaravelCron.php still render cleanly
                     without leaking the sentinel to the user. --}}
                @php
                    $schedulerOutputClean = trim((string) ($schedulerOutput ?? ''));
                    if ($schedulerOutputClean === 'notfound') {
                        $schedulerOutputClean = '';
                    }
                @endphp
                @if ($schedulerOutputClean !== '')
                    <div class="w-full">
                        <div class="mb-2 text-sm font-medium" style="color:#e4e4e7;">Salida:</div>
                        <pre
                            class="w-full whitespace-pre-wrap break-words border rounded px-4 py-3 text-sm font-mono min-h-[8rem] max-h-[40vh] overflow-auto"
                            style="background-color:#0a0a0a;color:#e4e4e7;border-color:#27272a;"
                        >{{ $schedulerOutputClean }}</pre>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
