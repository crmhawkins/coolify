<div>
    <x-slot:title>
        Artisan Commands | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Manager</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-artisan', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Artisan Commands</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-cron', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Cron</span></a>
        </div>

        {{-- IMPORTANT: this wrapper must NOT set overflow-x-hidden (or any
             other overflow value). Per the CSS spec, when overflow-x is
             not `visible`, the browser automatically clamps overflow-y
             to match, and any absolutely-positioned descendant (like
             the suggestions dropdown below the command input) gets
             clipped at the wrapper's box — which is exactly what made
             only 5-6 of the 10 popular commands visible in the live
             installation. Use min-w-0 on the wrapper instead so long
             strings inside .input still shrink to fit. --}}
        <div class="w-full min-w-0 flex flex-col gap-6">
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
                                {{-- Suggestions dropdown: dark theme matching
                                     the rest of Coolify. Background is a
                                     near-black #18181b, command names are
                                     white, descriptions are a muted grey.
                                     Hover switches to a slightly lighter
                                     #27272a so the row under the cursor
                                     stands out. Padding stays tight at
                                     py-1.5 so all 10 popular commands fit
                                     in one compact drop without scroll. --}}
                                <div
                                    class="absolute z-30 left-0 right-0 mt-1 border rounded shadow-lg overflow-auto"
                                    style="background-color:#18181b;border-color:#3f3f46;color:#e4e4e7;max-height:80vh;"
                                >
                                    @foreach ($filteredArtisanCommands as $cmd)
                                        <button
                                            type="button"
                                            class="w-full px-3 py-1.5 flex items-center gap-2 text-left transition-colors"
                                            style="color:#e4e4e7;background-color:#18181b;"
                                            onmouseover="this.style.backgroundColor='#27272a'"
                                            onmouseout="this.style.backgroundColor='#18181b'"
                                            wire:click="selectCommand(@js($cmd['name']))"
                                        >
                                            <span
                                                class="font-mono text-sm whitespace-nowrap min-w-[140px]"
                                                style="color:#ffffff;"
                                            >{{ $cmd['name'] }}</span>
                                            @if (! empty($cmd['description']))
                                                <span
                                                    class="text-xs truncate"
                                                    style="color:#a1a1aa;"
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
                            <button
                                type="button"
                                wire:click="run"
                                wire:loading.attr="disabled"
                                wire:target="run"
                                class="h-10 px-4 rounded font-semibold text-sm transition-all"
                                style="background-color:#8b5cf6;color:#ffffff;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                                onmouseover="this.style.backgroundColor='#7c3aed'"
                                onmouseout="this.style.backgroundColor='#8b5cf6'"
                            >
                                <span wire:loading.remove wire:target="run">Ejecutar</span>
                                <span wire:loading wire:target="run">Ejecutando…</span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-1 text-xs" style="color:#a1a1aa;">
                        Se ejecuta como:
                        <span class="font-mono" style="color:#c4b5fd;">{{ 'php /var/www/html/artisan '.trim((string) $selectedCommand) }}</span>
                    </div>

                    @if (! empty($selectedCommandDescription))
                        <div class="mt-2 text-xs" style="color:#a1a1aa;">
                            <span class="font-semibold" style="color:#e4e4e7;">Descripción:</span>
                            {{ $selectedCommandDescription }}
                        </div>
                    @endif
                </div>

                {{-- OUTPUT ROW: always full width, always visible, sits
                     directly below the command row. Dark theme to match
                     the rest of Coolify — near-black background, light
                     grey text, purple accent on the Limpiar action. --}}
                <div class="w-full">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium" style="color:#e4e4e7;">Salida:</label>
                        @if (! empty($output))
                            <button
                                type="button"
                                wire:click="$set('output', '')"
                                class="text-xs hover:underline transition-colors"
                                style="color:#c4b5fd;"
                            >
                                Limpiar
                            </button>
                        @endif
                    </div>
                    @if ($output === '')
                        <div
                            class="w-full rounded border border-dashed px-4 py-10 text-center text-sm min-h-[20rem] flex items-center justify-center"
                            style="background-color:#18181b;border-color:#3f3f46;color:#71717a;"
                        >
                            La salida del comando aparecerá aquí después de ejecutarlo.
                        </div>
                    @else
                        <pre
                            class="w-full whitespace-pre-wrap break-words border px-4 py-3 rounded text-sm font-mono min-h-[20rem] max-h-[70vh] overflow-auto"
                            style="background-color:#0a0a0a;color:#e4e4e7;border-color:#27272a;"
                        >{{ $output }}</pre>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
