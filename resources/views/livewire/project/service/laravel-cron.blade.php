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
                              style="background-color:#dcfce7;color:#166534;">
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color:#16a34a;"></span>
                            Scheduler activo
                        </span>
                    @elseif ($schedulerStatus === 'Stopped')
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                              style="background-color:#fee2e2;color:#991b1b;">
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color:#dc2626;"></span>
                            Scheduler detenido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                              style="background-color:#fef3c7;color:#92400e;">
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color:#d97706;"></span>
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
                         tasks like in the Apartamentos project. --}}
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($scheduledTasks as $taskIndex => $task)
                            <div
                                class="rounded-lg border shadow-sm"
                                style="background-color:#ffffff;border-color:#e5e7eb;color:#0b1220;"
                            >
                                {{-- Card header: command name + run button --}}
                                <div class="flex items-start justify-between gap-3 px-4 pt-3">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#0b1220;">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <span class="font-mono text-sm font-semibold truncate" style="color:#0b1220;" title="{{ $task['command'] }}">
                                            {{ $task['command'] ?: '—' }}
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="executeTaskNow({{ $taskIndex }})"
                                        wire:loading.attr="disabled"
                                        wire:target="executeTaskNow({{ $taskIndex }})"
                                        class="shrink-0 rounded px-2.5 py-1 text-xs font-semibold transition-opacity hover:opacity-80"
                                        style="background-color:#0b1220;color:#ffffff;"
                                    >
                                        <span wire:loading.remove wire:target="executeTaskNow({{ $taskIndex }})">Ejecutar</span>
                                        <span wire:loading wire:target="executeTaskNow({{ $taskIndex }})">…</span>
                                    </button>
                                </div>

                                {{-- Card body: badges for interval + next run --}}
                                <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                                    <span
                                        class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-mono"
                                        style="background-color:#f3f4f6;color:#374151;"
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
                                            style="background-color:#dbeafe;color:#1e40af;"
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
                                            style="background-color:#ecfccb;color:#3f6212;"
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
                                        style="border-color:#e5e7eb;color:#6b7280;"
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
                        <div class="mb-2 text-sm font-medium dark:text-white">Salida:</div>
                        <pre
                            class="w-full whitespace-pre-wrap break-words border rounded px-4 py-3 text-sm font-mono min-h-[8rem] max-h-[40vh] overflow-auto"
                            style="background-color:#ffffff;color:#0b1220;border-color:#e5e7eb;"
                        >{{ $schedulerOutput }}</pre>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
