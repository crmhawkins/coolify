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
                            @elseif ($selectedEnvContainerType === 'phpmyadmin')
                                {{-- phpMyAdmin does not mount Laravel's
                                     /var/www/html volume, so there is no
                                     .env file for us to edit here. Its
                                     configuration lives in the docker-
                                     compose env vars (PMA_HOST, etc).
                                     We render a friendly info card
                                     explaining that and stop. --}}
                                <div
                                    class="rounded-md px-5 py-6 flex items-start gap-3"
                                    style="background-color:#101013;border:1px dashed #3f3f46;color:#a1a1aa;"
                                >
                                    <svg class="w-6 h-6 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#86efac;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold mb-1" style="color:#ffffff;">No hace falta tocar nada aquí</div>
                                        <p class="text-xs leading-relaxed">
                                            <span class="font-mono" style="color:#c4b5fd;">phpmyadmin</span>
                                            no usa un <span class="font-mono">.env</span> de Laravel.
                                            Su configuración (host de base de datos, usuario, contraseña…) se define con
                                            variables de entorno en el <span class="font-mono">docker-compose.yml</span> del servicio
                                            (<span class="font-mono" style="color:#c4b5fd;">PMA_HOST</span>,
                                            <span class="font-mono" style="color:#c4b5fd;">PMA_USER</span>,
                                            <span class="font-mono" style="color:#c4b5fd;">PMA_PASSWORD</span>).
                                            Si necesitas editar el <span class="font-mono">.env</span> de Laravel, selecciona arriba el contenedor
                                            <span class="font-mono" style="color:#c4b5fd;">laravel</span>.
                                        </p>
                                    </div>
                                </div>
                            @elseif ($selectedEnvContainerType === 'nginx')
                                {{-- nginx shares the /var/www/html volume
                                     with the laravel container, so the
                                     file at /var/www/html/.env is the
                                     exact same physical file from both
                                     sides. To keep a single canonical
                                     source of truth we redirect the user
                                     to the laravel container instead of
                                     rendering a second editor for the
                                     same file. --}}
                                <div
                                    class="rounded-md px-5 py-6 flex items-start gap-3"
                                    style="background-color:#101013;border:1px dashed #3f3f46;color:#a1a1aa;"
                                >
                                    <svg class="w-6 h-6 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#86efac;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold mb-1" style="color:#ffffff;">nginx comparte el <span class="font-mono">.env</span> con laravel</div>
                                        <p class="text-xs leading-relaxed">
                                            Los contenedores <span class="font-mono" style="color:#c4b5fd;">nginx</span>
                                            y <span class="font-mono" style="color:#c4b5fd;">laravel</span>
                                            montan el mismo volumen <span class="font-mono">/var/www/html</span>, así que
                                            <span class="font-mono">/var/www/html/.env</span> es literalmente el mismo fichero
                                            desde ambos lados. Para evitar confusiones y tener una única fuente de verdad,
                                            edita el <span class="font-mono">.env</span> desde el contenedor
                                            <span class="font-mono" style="color:#c4b5fd;">laravel</span>.
                                        </p>
                                    </div>
                                </div>
                            @elseif (!$envFileExists)
                                <div
                                    class="rounded px-3 py-2 text-xs"
                                    style="background-color:rgba(245,158,11,0.1);color:#fcd34d;border:1px solid rgba(245,158,11,0.3);"
                                >
                                    Este proyecto no tiene <span class="font-mono">.env</span>
                                </div>
                            @else
                                {{-- Dirty-tracking wrapper. Captures the
                                     textarea value at mount time as the
                                     "initial" baseline, flips a dirty
                                     flag on every input event, and
                                     attaches a beforeunload listener
                                     that warns the user before they
                                     lose changes by navigating away.
                                     Listens for env-reloaded / env-saved
                                     events from the PHP side to reset
                                     the baseline after a fresh load or
                                     a successful save. --}}
                                <div
                                    x-data="{
                                        initialEnv: '',
                                        isDirty: false,
                                        beforeUnloadHandler: null,
                                        init() {
                                            this.initialEnv = (this.$refs.envTextarea && this.$refs.envTextarea.value) || '';
                                            this.isDirty = false;
                                            this.beforeUnloadHandler = (e) => {
                                                if (this.isDirty) {
                                                    e.preventDefault();
                                                    e.returnValue = 'Tienes cambios sin guardar en el archivo .env. ¿Seguro que quieres salir?';
                                                    return e.returnValue;
                                                }
                                            };
                                            window.addEventListener('beforeunload', this.beforeUnloadHandler);
                                        },
                                        destroy() {
                                            if (this.beforeUnloadHandler) {
                                                window.removeEventListener('beforeunload', this.beforeUnloadHandler);
                                                this.beforeUnloadHandler = null;
                                            }
                                        },
                                        checkDirty() {
                                            const current = (this.$refs.envTextarea && this.$refs.envTextarea.value) || '';
                                            this.isDirty = current !== this.initialEnv;
                                        },
                                        markClean() {
                                            const current = (this.$refs.envTextarea && this.$refs.envTextarea.value) || '';
                                            this.initialEnv = current;
                                            this.isDirty = false;
                                        }
                                    }"
                                    x-on:env-reloaded.window="markClean()"
                                    x-on:env-saved.window="markClean()"
                                >
                                    <label for="env_content" class="block text-xs font-medium mb-1.5" style="color:#e4e4e7;">
                                        Contenido del archivo <span class="font-mono" style="color:#c4b5fd;">.env</span>
                                    </label>
                                    <textarea
                                        id="env_content"
                                        x-ref="envTextarea"
                                        wire:model="envContent"
                                        x-on:input="checkDirty()"
                                        rows="18"
                                        class="w-full rounded px-3 py-2 text-sm font-mono resize-y"
                                        style="background-color:#0a0a0a;color:#e4e4e7;border:1px solid #3f3f46;min-height:320px;line-height:1.5;"
                                        spellcheck="false"
                                    ></textarea>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
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
                                            wire:confirm="¿Recargar el .env desde el contenedor? Se descartarán los cambios sin guardar que tengas en el editor."
                                            class="rounded px-3 py-1.5 text-xs font-semibold transition-colors"
                                            style="background-color:#27272a;color:#e4e4e7;border:1px solid #3f3f46;"
                                            onmouseover="this.style.backgroundColor='#3f3f46'"
                                            onmouseout="this.style.backgroundColor='#27272a'"
                                        >
                                            <span wire:loading.remove wire:target="loadEnvVariables">Recargar</span>
                                            <span wire:loading wire:target="loadEnvVariables">Cargando…</span>
                                        </button>
                                        <span
                                            x-show="isDirty"
                                            x-cloak
                                            class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-[11px] font-semibold"
                                            style="background-color:rgba(245,158,11,0.12);color:#fcd34d;border:1px solid rgba(245,158,11,0.35);"
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                            </svg>
                                            Tienes cambios sin guardar
                                        </span>
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
                            Edita los valores de PHP del contenedor Laravel. Se guardan en
                            <span class="font-mono" style="color:#c4b5fd;">conf.d/zzz-coolify-laravel-manager.ini</span>
                            dentro del contenedor y se recarga PHP-FPM automáticamente.
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
                            @elseif ($selectedContainerType === 'nginx')
                                {{-- nginx containers run only the web server —
                                     no PHP interpreter, so the ini editor has
                                     nothing to edit here. We still keep the
                                     container selector visible above so the
                                     user can switch to another container
                                     without navigating away. --}}
                                <div
                                    class="rounded-md px-5 py-6 flex items-start gap-3"
                                    style="background-color:#101013;border:1px dashed #3f3f46;color:#a1a1aa;"
                                >
                                    <svg class="w-6 h-6 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#86efac;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold mb-1" style="color:#ffffff;">No hace falta tocar nada aquí</div>
                                        <p class="text-xs leading-relaxed">
                                            Este contenedor es un <span class="font-mono" style="color:#c4b5fd;">nginx</span>
                                            y no ejecuta PHP, así que no tiene valores de <span class="font-mono">php.ini</span> que ajustar.
                                            Si quieres editar la configuración PHP, selecciona arriba el contenedor
                                            <span class="font-mono" style="color:#c4b5fd;">laravel</span> o
                                            <span class="font-mono" style="color:#c4b5fd;">phpmyadmin</span>.
                                        </p>
                                    </div>
                                </div>
                            @elseif (!empty($phpIniSettings))
                                {{-- Editable grid. Each input is bound
                                     by POSITION inside PHP_INI_EDITABLE_KEYS
                                     — see the comment on the property for
                                     why we can't use the ini key as the
                                     wire:model path (Livewire treats dots
                                     as nested array access and breaks
                                     `opcache.memory_consumption`).

                                     Wrapped in an Alpine component that
                                     tracks dirty state by serialising
                                     every input's value into a single
                                     string and comparing against the
                                     baseline captured on init. Any input
                                     event flips the flag and a
                                     beforeunload handler blocks
                                     navigation until the user saves or
                                     explicitly discards. --}}
                                <div
                                    x-data="{
                                        initialSnapshot: '',
                                        isDirty: false,
                                        beforeUnloadHandler: null,
                                        snapshot() {
                                            const inputs = this.$root.querySelectorAll('input[data-ini-key]');
                                            return Array.from(inputs)
                                                .map(i => i.dataset.iniKey + '=' + (i.value || ''))
                                                .join('\n');
                                        },
                                        init() {
                                            this.initialSnapshot = this.snapshot();
                                            this.isDirty = false;
                                            this.beforeUnloadHandler = (e) => {
                                                if (this.isDirty) {
                                                    e.preventDefault();
                                                    e.returnValue = 'Tienes cambios sin guardar en la configuración de PHP. ¿Seguro que quieres salir?';
                                                    return e.returnValue;
                                                }
                                            };
                                            window.addEventListener('beforeunload', this.beforeUnloadHandler);
                                        },
                                        destroy() {
                                            if (this.beforeUnloadHandler) {
                                                window.removeEventListener('beforeunload', this.beforeUnloadHandler);
                                                this.beforeUnloadHandler = null;
                                            }
                                        },
                                        checkDirty() {
                                            this.isDirty = this.snapshot() !== this.initialSnapshot;
                                        },
                                        markClean() {
                                            this.initialSnapshot = this.snapshot();
                                            this.isDirty = false;
                                        }
                                    }"
                                    x-on:phpini-reloaded.window="markClean()"
                                    x-on:phpini-saved.window="markClean()"
                                    x-on:input.debounce.50ms="checkDirty()"
                                >
                                @php
                                    $phpIniDescriptions = [
                                        'upload_max_filesize' => 'Tamaño máximo de un archivo subido (ej: 100M)',
                                        'post_max_size' => 'Tamaño máximo de un POST (debe ser ≥ upload_max_filesize)',
                                        'max_execution_time' => 'Tiempo máximo en segundos para un script (0 = sin límite)',
                                        'max_input_time' => 'Tiempo máximo en segundos para parsear la request (-1 = sin límite)',
                                        'memory_limit' => 'Memoria máxima que puede usar un script (ej: 512M)',
                                        'max_input_vars' => 'Número máximo de variables de entrada en una request',
                                        'max_file_uploads' => 'Número máximo de archivos en una sola subida',
                                        'opcache.memory_consumption' => 'MB de memoria compartida para OPcache (ej: 256)',
                                        'opcache.max_accelerated_files' => 'Máximo de archivos PHP cacheados por OPcache',
                                        'opcache.revalidate_freq' => 'Segundos entre revalidaciones de archivos (0 = siempre)',
                                        'realpath_cache_size' => 'Tamaño del cache de resolución de rutas (ej: 4096K)',
                                        'realpath_cache_ttl' => 'TTL en segundos del cache de rutas',
                                    ];
                                    $editableKeys = \App\Livewire\Project\Service\LaravelManager::PHP_INI_EDITABLE_KEYS;
                                @endphp
                                <div class="grid gap-4 md:grid-cols-2">
                                    @foreach ($editableKeys as $idx => $setting)
                                        <div
                                            class="rounded-md px-5 py-4 transition-colors"
                                            style="background-color:#101013;border:1px solid #27272a;"
                                            onmouseover="this.style.borderColor='#3f3f46'"
                                            onmouseout="this.style.borderColor='#27272a'"
                                        >
                                            <label
                                                for="php_ini_{{ $idx }}"
                                                class="flex items-center gap-1.5 text-xs font-mono mb-2.5 truncate"
                                                style="color:#a1a1aa;"
                                                title="{{ $phpIniDescriptions[$setting] ?? $setting }}"
                                            >
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#71717a;">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                <span class="truncate">{{ $setting }}</span>
                                            </label>
                                            <input
                                                id="php_ini_{{ $idx }}"
                                                type="text"
                                                wire:model="phpIniEditableValues.{{ $idx }}"
                                                data-ini-key="{{ $setting }}"
                                                class="w-full rounded-md px-3 py-2 text-sm font-mono font-semibold transition-colors focus:outline-none"
                                                style="background-color:#18181b;color:#c4b5fd;border:1px solid #3f3f46;"
                                                onfocus="this.style.borderColor='#8b5cf6';this.style.boxShadow='0 0 0 2px rgba(139,92,246,0.2)'"
                                                onblur="this.style.borderColor='#3f3f46';this.style.boxShadow='none'"
                                                placeholder="{{ $phpIniSettings[$setting] ?? '' }}"
                                                spellcheck="false"
                                                autocomplete="off"
                                            />
                                            @if (! empty($phpIniDescriptions[$setting]))
                                                <p class="mt-1.5 text-[11px] leading-snug" style="color:#71717a;">
                                                    {{ $phpIniDescriptions[$setting] }}
                                                </p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                <div
                                    class="rounded px-3 py-2 text-xs"
                                    style="background-color:rgba(139,92,246,0.08);color:#c4b5fd;border:1px solid rgba(139,92,246,0.25);"
                                >
                                    <strong>Nota:</strong> Al guardar se crea el fichero
                                    <span class="font-mono">/usr/local/etc/php/conf.d/zzz-coolify-laravel-manager.ini</span>
                                    dentro del contenedor y se recarga PHP-FPM con SIGUSR2.
                                    Si no se refleja al instante, reinicia el contenedor desde Coolify.
                                </div>

                                <div class="flex flex-wrap items-center gap-2 pt-1">
                                    <button
                                        type="button"
                                        wire:click="savePhpIniSettings"
                                        wire:loading.attr="disabled"
                                        wire:target="savePhpIniSettings"
                                        class="rounded px-3 py-1.5 text-xs font-semibold transition-all disabled:opacity-60"
                                        style="background-color:#8b5cf6;color:#ffffff;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                                        onmouseover="this.style.backgroundColor='#7c3aed'"
                                        onmouseout="this.style.backgroundColor='#8b5cf6'"
                                    >
                                        <span wire:loading.remove wire:target="savePhpIniSettings">Guardar cambios</span>
                                        <span wire:loading wire:target="savePhpIniSettings">Guardando…</span>
                                    </button>
                                    @php
                                        $defaultsLabel = match ($selectedContainerType) {
                                            'phpmyadmin' => 'phpMyAdmin',
                                            default => 'Laravel Rootkit',
                                        };
                                    @endphp
                                    <button
                                        type="button"
                                        wire:click="applyRecommendedPhpDefaults"
                                        wire:confirm="Esto rellenará los campos con los defaults recomendados para {{ $defaultsLabel }}. Tendrás que pulsar Guardar para aplicarlos. ¿Continuar?"
                                        class="rounded px-3 py-1.5 text-xs font-semibold transition-colors"
                                        style="background-color:#27272a;color:#c4b5fd;border:1px solid #3f3f46;"
                                        onmouseover="this.style.backgroundColor='#3f3f46'"
                                        onmouseout="this.style.backgroundColor='#27272a'"
                                    >
                                        Aplicar defaults {{ $defaultsLabel }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="loadPhpIniSettings"
                                        wire:loading.attr="disabled"
                                        wire:target="loadPhpIniSettings"
                                        wire:confirm="¿Recargar los valores desde el contenedor? Se descartarán los cambios sin guardar que tengas en el formulario."
                                        class="rounded px-3 py-1.5 text-xs font-semibold transition-colors"
                                        style="background-color:#27272a;color:#e4e4e7;border:1px solid #3f3f46;"
                                        onmouseover="this.style.backgroundColor='#3f3f46'"
                                        onmouseout="this.style.backgroundColor='#27272a'"
                                    >
                                        <span wire:loading.remove wire:target="loadPhpIniSettings">Recargar valores</span>
                                        <span wire:loading wire:target="loadPhpIniSettings">Cargando…</span>
                                    </button>
                                    <span
                                        x-show="isDirty"
                                        x-cloak
                                        class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-[11px] font-semibold"
                                        style="background-color:rgba(245,158,11,0.12);color:#fcd34d;border:1px solid rgba(245,158,11,0.35);"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                        </svg>
                                        Tienes cambios sin guardar
                                    </span>
                                </div>
                                </div>{{-- /x-data php.ini dirty wrapper --}}
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
