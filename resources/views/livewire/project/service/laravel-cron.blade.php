<div>
    <x-slot:title>
        Laravel Cron | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item" target="_blank" href="{{ $service->documentation() }}"><span class="menu-item-label">Documentation</span>
                <x-external-link /></a>
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

                    <x-forms.button
                        wire:click="loadScheduleList"
                        wire:loading.attr="disabled"
                        wire:target="loadScheduleList"
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

                                {{-- Card body: badges for interval + next run --}}
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
                     something, or the source-level fallback has a message --}}
                @if (! empty($schedulerOutput))
                    <div class="w-full">
                        <div class="mb-2 text-sm font-medium" style="color:#e4e4e7;">Salida:</div>
                        <pre
                            class="w-full whitespace-pre-wrap break-words border rounded px-4 py-3 text-sm font-mono min-h-[8rem] max-h-[40vh] overflow-auto"
                            style="background-color:#0a0a0a;color:#e4e4e7;border-color:#27272a;"
                        >{{ $schedulerOutput }}</pre>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
