<div>
    <x-slot:title>
        Laravel Manager | Coolify
    </x-slot>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('error', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.error('[Laravel Manager Error]', message);
            });

            Livewire.on('success', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.log('[Laravel Manager Success]', message);
            });

            Livewire.on('warning', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.warn('[Laravel Manager Warning]', message);
            });
        });
    </script>

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Manager</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-artisan', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Artisan Commands</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-cron', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Cron</span></a>
        </div>

        {{-- Same lesson as Artisan / Cron: NO overflow-x-hidden on the
             wrapper, use min-w-0 so long strings in textareas / selects
             shrink to fit without clipping any absolutely-positioned
             descendant. --}}
        <div class="w-full min-w-0 flex flex-col gap-6">
            <div>
                <h2 class="text-xl font-bold dark:text-white">Laravel Manager</h2>
                <div class="subtitle">Configuración y gestión del contenedor Laravel.</div>
            </div>

            @if (empty($laravelContainers))
                <div class="rounded border border-coolgray-300 dark:border-coolgray-700 p-4 text-sm text-neutral-500">
                    No Laravel containers detected in this service.
                </div>
            @else
                {{-- ==================== DETECTED CONTAINERS ==================== --}}
                <div
                    class="rounded-lg border shadow-lg"
                    style="background-color:#18181b;border-color:#27272a;color:#e4e4e7;"
                >
                    <div class="px-5 py-4 border-b" style="border-color:#27272a;">
                        <h3 class="text-base font-semibold" style="color:#ffffff;">
                            Contenedores Laravel detectados
                        </h3>
                        <p class="mt-0.5 text-xs" style="color:#a1a1aa;">
                            {{ count($laravelContainers) }}
                            {{ count($laravelContainers) === 1 ? 'contenedor encontrado' : 'contenedores encontrados' }}
                        </p>
                    </div>
                    <ul class="px-5 py-3 space-y-2">
                        @foreach ($laravelContainers as $container)
                            @php
                                $isRunning = str($container['status'])->contains('running');
                            @endphp
                            <li class="flex items-center gap-2 text-sm">
                                <span class="inline-block w-2 h-2 rounded-full" style="background-color: {{ $isRunning ? '#22c55e' : '#ef4444' }};"></span>
                                <span class="font-mono" style="color:#ffffff;">{{ $container['name'] }}</span>
                                <span
                                    class="inline-flex items-center rounded px-2 py-0.5 text-xs font-mono"
                                    style="background-color:#27272a;color:#a1a1aa;border:1px solid #3f3f46;"
                                >
                                    {{ $container['status'] }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- ==================== .env CONFIG ==================== --}}
                <div
                    class="rounded-lg border shadow-lg"
                    style="background-color:#18181b;border-color:#27272a;color:#e4e4e7;"
                >
                    <div class="px-5 py-4 border-b" style="border-color:#27272a;">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#c4b5fd;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <h3 class="text-base font-semibold" style="color:#ffffff;">Configuración .env</h3>
                        </div>
                        <p class="mt-0.5 text-xs" style="color:#a1a1aa;">
                            Gestiona las variables de entorno de Laravel desde el archivo <span class="font-mono" style="color:#c4b5fd;">.env</span>.
                        </p>
                    </div>

                    <div class="px-5 py-4 space-y-4">
                        <div>
                            <label for="env_container" class="block text-xs font-medium mb-1.5" style="color:#e4e4e7;">Contenedor:</label>
                            <select id="env_container"
                                wire:model.live="selectedContainerForEnv"
                                wire:change="loadEnvVariables"
                                class="input w-full">
                                <option value="">-- Selecciona un contenedor --</option>
                                @foreach ($laravelContainers as $container)
                                    <option value="{{ $container['id'] }}"
                                        @if (!str($container['status'])->contains('running')) disabled @endif>
                                        {{ $container['name'] }}
                                        @if (!str($container['status'])->contains('running'))
                                            (no running)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @if ($selectedContainerForEnv)
                            @if ($isLoadingEnv)
                                <div class="flex items-center gap-2 text-sm" style="color:#a1a1aa;">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Cargando archivo .env…
                                </div>
                            @elseif (!$envFileExists)
                                <div
                                    class="rounded px-3 py-2 text-xs"
                                    style="background-color:rgba(245,158,11,0.1);color:#fcd34d;border:1px solid rgba(245,158,11,0.3);"
                                >
                                    Este proyecto no tiene <span class="font-mono">.env</span>
                                </div>
                            @else
                                <div>
                                    <label for="env_content" class="block text-xs font-medium mb-1.5" style="color:#e4e4e7;">
                                        Contenido del archivo <span class="font-mono" style="color:#c4b5fd;">.env</span>
                                    </label>
                                    <textarea
                                        id="env_content"
                                        wire:model="envContent"
                                        rows="18"
                                        class="w-full rounded px-3 py-2 text-sm font-mono resize-y"
                                        style="background-color:#0a0a0a;color:#e4e4e7;border:1px solid #3f3f46;min-height:320px;line-height:1.5;"
                                        spellcheck="false"
                                    ></textarea>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            wire:click="saveEnvFile"
                                            wire:loading.attr="disabled"
                                            wire:target="saveEnvFile"
                                            class="rounded px-3 py-1.5 text-xs font-semibold transition-all"
                                            style="background-color:#8b5cf6;color:#ffffff;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                                            onmouseover="this.style.backgroundColor='#7c3aed'"
                                            onmouseout="this.style.backgroundColor='#8b5cf6'"
                                        >
                                            <span wire:loading.remove wire:target="saveEnvFile">Guardar cambios</span>
                                            <span wire:loading wire:target="saveEnvFile">Guardando…</span>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="loadEnvVariables"
                                            wire:loading.attr="disabled"
                                            wire:target="loadEnvVariables"
                                            class="rounded px-3 py-1.5 text-xs font-semibold transition-colors"
                                            style="background-color:#27272a;color:#e4e4e7;border:1px solid #3f3f46;"
                                            onmouseover="this.style.backgroundColor='#3f3f46'"
                                            onmouseout="this.style.backgroundColor='#27272a'"
                                        >
                                            <span wire:loading.remove wire:target="loadEnvVariables">Recargar</span>
                                            <span wire:loading wire:target="loadEnvVariables">Cargando…</span>
                                        </button>
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- ==================== PHP INI ==================== --}}
                <div
                    class="rounded-lg border shadow-lg"
                    style="background-color:#18181b;border-color:#27272a;color:#e4e4e7;"
                >
                    <div class="px-5 py-4 border-b" style="border-color:#27272a;">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#c4b5fd;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <h3 class="text-base font-semibold" style="color:#ffffff;">Configuración PHP (php.ini)</h3>
                        </div>
                        <p class="mt-0.5 text-xs" style="color:#a1a1aa;">
                            Valores actuales de PHP en el contenedor Laravel seleccionado.
                        </p>
                    </div>

                    <div class="px-5 py-4 space-y-4">
                        <div>
                            <label for="php_ini_container" class="block text-xs font-medium mb-1.5" style="color:#e4e4e7;">Contenedor:</label>
                            <select id="php_ini_container"
                                wire:model.live="selectedContainerForPhpIni"
                                wire:change="loadPhpIniSettings"
                                class="input w-full">
                                <option value="">-- Selecciona un contenedor --</option>
                                @foreach ($laravelContainers as $container)
                                    <option value="{{ $container['id'] }}"
                                        @if (!str($container['status'])->contains('running')) disabled @endif>
                                        {{ $container['name'] }}
                                        @if (!str($container['status'])->contains('running'))
                                            (no running)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @if ($selectedContainerForPhpIni)
                            @if ($isLoadingPhpIni)
                                <div class="flex items-center gap-2 text-sm" style="color:#a1a1aa;">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Cargando configuración PHP…
                                </div>
                            @elseif (!empty($phpIniSettings))
                                <div class="grid gap-2 md:grid-cols-2">
                                    @foreach ($phpIniSettings as $setting => $value)
                                        <div
                                            class="rounded px-3 py-2 flex items-center justify-between gap-3"
                                            style="background-color:#0a0a0a;border:1px solid #27272a;"
                                        >
                                            <span class="text-xs font-mono truncate" style="color:#a1a1aa;" title="{{ $setting }}">
                                                {{ $setting }}
                                            </span>
                                            <span class="text-xs font-mono font-semibold shrink-0" style="color:#c4b5fd;">
                                                {{ $value }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div
                                    class="rounded px-3 py-2 text-xs"
                                    style="background-color:rgba(245,158,11,0.1);color:#fcd34d;border:1px solid rgba(245,158,11,0.3);"
                                >
                                    No se pudieron cargar las configuraciones de PHP. Asegúrate de que el contenedor esté en ejecución.
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
