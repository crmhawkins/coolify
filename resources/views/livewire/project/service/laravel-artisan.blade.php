<div>
    <x-slot:title>
        Artisan Commands | Coolify
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
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-artisan', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Artisan Commands</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-cron', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Cron</span></a>
        </div>

        {{-- IMPORTANT: explicit flex-col here. The previous markup used the
             .box-without-bg utility which applies `flex` without
             `flex-col`, so the input row and the output block ended up
             side-by-side on the same horizontal flex line. Using an
             explicit w-full / flex-col container fixes that once and for
             all regardless of what the utility class does. --}}
        <div class="w-full flex flex-col gap-6 overflow-x-hidden">
            <h2 class="text-xl font-bold dark:text-white">Artisan Commands</h2>

            @if (empty($laravelContainers))
                <div class="rounded border border-coolgray-300 dark:border-coolgray-700 p-4 text-sm text-neutral-500">
                    No Laravel containers detected in this service.
                </div>
            @else
                {{-- COMMAND ROW: input + run button --}}
                <div class="w-full">
                    <label class="block text-sm font-medium dark:text-white mb-2">Comando:</label>

                    <div class="flex items-start w-full gap-2">
                        <div class="relative flex-1 min-w-0">
                            <input
                                type="text"
                                wire:model.live.debounce.200ms="selectedCommand"
                                wire:focus="showPopularCommands"
                                wire:keydown.enter.prevent="run"
                                class="input w-full min-w-0"
                                placeholder="Ej: migrate --force"
                                autocomplete="off"
                                spellcheck="false"
                            />

                            @if (! empty($filteredArtisanCommands))
                                {{-- Suggestions dropdown: inline styles win
                                     over any cascade fight the Coolify
                                     theme might put up. max-height is
                                     pinned via inline style AND a Tailwind
                                     arbitrary so whichever the live CSS
                                     compiles first, the dropdown still gets
                                     enough room for all 10 popular
                                     commands without forcing the user to
                                     scroll. Padding is tightened from py-2
                                     to py-1.5 to keep each row compact. --}}
                                <div
                                    class="absolute z-30 left-0 right-0 mt-1 bg-white border border-gray-300 rounded shadow-lg overflow-auto"
                                    style="background-color:#ffffff;color:#0b1220;max-height:80vh;"
                                >
                                    @foreach ($filteredArtisanCommands as $cmd)
                                        <button
                                            type="button"
                                            class="w-full px-3 py-1.5 hover:bg-gray-100 flex items-center gap-2 text-left"
                                            style="color:#0b1220;background-color:#ffffff;"
                                            wire:click="selectCommand(@js($cmd['name']))"
                                        >
                                            <span
                                                class="font-mono text-sm whitespace-nowrap min-w-[140px]"
                                                style="color:#0b1220;"
                                            >{{ $cmd['name'] }}</span>
                                            @if (! empty($cmd['description']))
                                                <span
                                                    class="text-xs truncate"
                                                    style="color:#374151;"
                                                    title="{{ $cmd['description'] }}"
                                                >
                                                    — {{ $cmd['description'] }}
                                                </span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="shrink-0">
                            <x-forms.button
                                wire:click="run"
                                wire:loading.attr="disabled"
                                wire:target="run"
                                class="bg-coollabs h-10 px-4"
                            >
                                <span wire:loading.remove wire:target="run">Ejecutar</span>
                                <span wire:loading wire:target="run">Ejecutando…</span>
                            </x-forms.button>
                        </div>
                    </div>

                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Se ejecuta como:
                        <span class="font-mono">{{ 'php /var/www/html/artisan '.trim((string) $selectedCommand) }}</span>
                    </div>

                    @if (! empty($selectedCommandDescription))
                        <div class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                            <span class="font-semibold">Descripción:</span>
                            {{ $selectedCommandDescription }}
                        </div>
                    @endif
                </div>

                {{-- OUTPUT ROW: always full width, always visible, sits
                     directly below the command row. Never, under any
                     circumstances, on the right side of the button. --}}
                <div class="w-full">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium dark:text-white">Salida:</label>
                        @if (! empty($output))
                            <button
                                type="button"
                                wire:click="$set('output', '')"
                                class="text-xs text-neutral-500 dark:text-neutral-400 hover:underline"
                            >
                                Limpiar
                            </button>
                        @endif
                    </div>
                    @if ($output === '')
                        <div class="w-full rounded border border-dashed border-coolgray-300 dark:border-coolgray-600 px-4 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400 min-h-[20rem] flex items-center justify-center">
                            La salida del comando aparecerá aquí después de ejecutarlo.
                        </div>
                    @else
                        {{-- Same fix as the suggestions dropdown: the live
                             container has a white background for this <pre>
                             regardless of the dark theme, but
                             `dark:text-gray-100` wins and paints the text
                             light-grey on white (invisible). Inline styles
                             pin both the background and the text colour so
                             the output is always readable on any theme. --}}
                        <pre
                            class="w-full whitespace-pre-wrap break-words border border-gray-300 px-4 py-3 rounded text-sm font-mono min-h-[20rem] max-h-[70vh] overflow-auto"
                            style="background-color:#ffffff;color:#0b1220;"
                        >{{ $output }}</pre>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
