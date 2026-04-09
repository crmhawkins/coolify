<div>
    <x-slot:title>
        WordPress Manager | Coolify
    </x-slot>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('error', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.error('[WordPress Manager Error]', message);
            });

            Livewire.on('success', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.log('[WordPress Manager Success]', message);
            });

            Livewire.on('warning', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.warn('[WordPress Manager Warning]', message);
            });
        });
    </script>

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        {{-- Full service sub-menu so navigating INTO WordPress Manager
             keeps every other tab reachable — previously the sub-menu
             only had General + WordPress Manager, which meant the user
             had to click "General" every time they wanted to reach
             Environment Variables, Webhooks, Danger Zone, etc. Now we
             mirror what the general configuration page shows. --}}
        <div class="sub-menu-wrapper">
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.environment-variables', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Environment Variables</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.storages', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Persistent Storages</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.scheduled-tasks.show', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Scheduled Tasks</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.wordpress-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">WordPress Manager</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.webhooks', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Webhooks</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.resource-operations', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Resource Operations</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.tags', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Tags</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.danger', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Danger Zone</span></a>
        </div>

        {{-- Same min-w-0 / no-overflow-x-hidden lesson as laravel-manager.
             Every visual style below is INLINE (background, border,
             color, padding, radius, font) so nothing depends on Tailwind
             utility classes that may be missing from the compiled
             bundle on the Coolify production container (see commit
             d15ba255d for the story behind this decision). --}}
        <div class="w-full min-w-0 flex flex-col gap-6">
            <div>
                <h2 class="text-xl font-bold dark:text-white">WordPress Manager</h2>
                <div class="subtitle">Configuración y gestión de los contenedores WordPress.</div>
            </div>

            @if (empty($wordpressContainers))
                <div style="border-radius:0.5rem;border:1px solid #3f3f46;padding:1rem;font-size:0.875rem;color:#a1a1aa;background-color:#18181b;">
                    No WordPress containers detected in this service.
                </div>
            @else
                {{-- ==================== DETECTED CONTAINERS ==================== --}}
                <div style="border-radius:0.5rem;border:1px solid #27272a;background-color:#18181b;color:#e4e4e7;box-shadow:0 10px 15px -3px rgba(0,0,0,0.3);">
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #27272a;">
                        <h3 style="margin:0;font-size:1rem;font-weight:600;color:#ffffff;">
                            Contenedores WordPress detectados
                        </h3>
                        <p style="margin:0.125rem 0 0 0;font-size:0.75rem;color:#a1a1aa;">
                            {{ count($wordpressContainers) }}
                            {{ count($wordpressContainers) === 1 ? 'contenedor encontrado' : 'contenedores encontrados' }}
                        </p>
                    </div>
                    <ul style="list-style:none;margin:0;padding:0.75rem 1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
                        @foreach ($wordpressContainers as $container)
                            @php $isRunning = str($container['status'])->contains('running'); @endphp
                            <li style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;">
                                <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:{{ $isRunning ? '#22c55e' : '#ef4444' }};"></span>
                                <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#ffffff;">{{ $container['name'] }}</span>
                                <span style="display:inline-flex;align-items:center;border-radius:0.25rem;padding:0.125rem 0.5rem;font-size:0.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background-color:#27272a;color:#a1a1aa;border:1px solid #3f3f46;">
                                    {{ $container['status'] }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- ==================== FIX WP-CONTENT PERMISSIONS ==================== --}}
                {{-- The one the user really wanted: a button that wraps
                     the chown/chmod dance they had to remember manually
                     every time they uploaded files as root. Delegates
                     every shell line to the
                     FixWordPressContentPermissions action so the UI
                     and the auto-run-on-redeploy job share identical
                     logic. Includes the 3 verification checks from the
                     briefing and surfaces a green/red badge based on
                     whether www-data can actually write. --}}
                <div style="border-radius:0.5rem;border:1px solid #27272a;background-color:#18181b;color:#e4e4e7;box-shadow:0 10px 15px -3px rgba(0,0,0,0.3);">
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #27272a;">
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0;color:#c4b5fd;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <h3 style="margin:0;font-size:1rem;font-weight:600;color:#ffffff;">Permisos de wp-content</h3>
                        </div>
                        <p style="margin:0.125rem 0 0 0;font-size:0.75rem;color:#a1a1aa;line-height:1.5;">
                            Ajusta <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">chown</span> y <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">chmod</span> sobre <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">/var/www/html/wp-content</span> dentro del contenedor WordPress, asegura que <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">wp-content/upgrade</span> exista y verifica que <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">www-data</span> realmente puede escribir. Úsalo cuando veas el popup "Datos de conexión / FTP" al instalar plugins o tras subir ficheros como root.
                        </p>
                    </div>

                    <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.75rem;">
                        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
                            <button
                                type="button"
                                wire:click="fixWpContentPermissions"
                                wire:loading.attr="disabled"
                                wire:target="fixWpContentPermissions"
                                wire:confirm="¿Ajustar owner y permisos de wp-content dentro del contenedor WordPress? Es seguro ejecutarlo varias veces."
                                style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 0.875rem;border-radius:0.375rem;font-size:0.8125rem;font-weight:600;background-color:#8b5cf6;color:#ffffff;border:1px solid #7c3aed;cursor:pointer;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                                onmouseover="this.style.backgroundColor='#7c3aed'"
                                onmouseout="this.style.backgroundColor='#8b5cf6'"
                            >
                                <svg width="14" height="14" style="width:14px;height:14px;flex-shrink:0;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                                <span wire:loading.remove wire:target="fixWpContentPermissions">Arreglar permisos</span>
                                <span wire:loading wire:target="fixWpContentPermissions">Arreglando…</span>
                            </button>
                            <span style="font-size:0.6875rem;color:#71717a;">
                                Se ejecuta automáticamente tras cada <strong>Redeploy</strong> y <strong>Pull &amp; Restart</strong>.
                            </span>
                        </div>

                        @if (! empty($fixPermsResult))
                            @if (! empty($fixPermsResult['errors']))
                                @foreach ($fixPermsResult['errors'] as $err)
                                    <div style="padding:0.5rem 0.75rem;border-radius:0.375rem;font-size:0.75rem;background-color:rgba(239,68,68,0.12);color:#fca5a5;border:1px solid rgba(239,68,68,0.3);">
                                        {{ $err }}
                                    </div>
                                @endforeach
                            @endif

                            @if (! empty($fixPermsResult['containers']))
                                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                                    @foreach ($fixPermsResult['containers'] as $c)
                                        <div style="border-radius:0.375rem;border:1px solid {{ $c['ok'] ? 'rgba(34,197,94,0.3)' : 'rgba(239,68,68,0.3)' }};background-color:{{ $c['ok'] ? 'rgba(34,197,94,0.08)' : 'rgba(239,68,68,0.08)' }};padding:0.75rem;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                                                <div style="min-width:0;flex:1 1 0%;">
                                                    <div style="display:flex;align-items:center;gap:0.5rem;">
                                                        @if ($c['ok'])
                                                            <svg width="14" height="14" style="width:14px;height:14px;flex-shrink:0;color:#4ade80;display:block;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        @else
                                                            <svg width="14" height="14" style="width:14px;height:14px;flex-shrink:0;color:#f87171;display:block;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                            </svg>
                                                        @endif
                                                        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:0.8125rem;font-weight:600;color:#ffffff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $c['container'] }}</span>
                                                    </div>
                                                    <p style="margin:0.25rem 0 0 0;font-size:0.6875rem;color:#a1a1aa;">en servidor {{ $c['server'] }} · exit {{ $c['exit_code'] }}</p>
                                                </div>
                                                @if ($c['write_test_ok'])
                                                    <span style="flex-shrink:0;display:inline-flex;align-items:center;gap:0.25rem;border-radius:0.25rem;padding:0.25rem 0.5rem;font-size:0.6875rem;font-weight:700;background-color:rgba(34,197,94,0.15);color:#4ade80;border:1px solid rgba(34,197,94,0.3);">
                                                        ✓ WordPress puede escribir
                                                    </span>
                                                @else
                                                    <span style="flex-shrink:0;display:inline-flex;align-items:center;gap:0.25rem;border-radius:0.25rem;padding:0.25rem 0.5rem;font-size:0.6875rem;font-weight:700;background-color:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.3);">
                                                        ✗ No puede escribir
                                                    </span>
                                                @endif
                                            </div>
                                            @if (! empty($c['output']))
                                                <pre style="margin:0.5rem 0 0 0;max-height:14rem;overflow:auto;white-space:pre-wrap;word-break:break-word;font-size:0.6875rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background-color:#0a0a0a;color:#e4e4e7;border:1px solid #27272a;border-radius:0.25rem;padding:0.5rem;">{{ $c['output'] }}</pre>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <button
                                type="button"
                                wire:click="$set('fixPermsResult', null)"
                                style="align-self:flex-start;padding:0.25rem 0.625rem;border-radius:0.25rem;font-size:0.6875rem;background-color:#27272a;color:#a1a1aa;border:1px solid #3f3f46;cursor:pointer;"
                                onmouseover="this.style.backgroundColor='#3f3f46'"
                                onmouseout="this.style.backgroundColor='#27272a'"
                            >
                                Cerrar resultado
                            </button>
                        @endif
                    </div>
                </div>

                {{-- ==================== SYNC URLS ==================== --}}
                <div style="border-radius:0.5rem;border:1px solid #27272a;background-color:#18181b;color:#e4e4e7;box-shadow:0 10px 15px -3px rgba(0,0,0,0.3);">
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #27272a;">
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0;color:#c4b5fd;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            <h3 style="margin:0;font-size:1rem;font-weight:600;color:#ffffff;">Sincronizar URLs</h3>
                        </div>
                        <p style="margin:0.125rem 0 0 0;font-size:0.75rem;color:#a1a1aa;">
                            Reemplaza todas las URLs antiguas por las nuevas en la base de datos y regenera los ficheros CSS de Elementor. Después del reemplazo también corrige los permisos de wp-content automáticamente.
                        </p>
                    </div>

                    <form wire:submit="syncUrls" style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.75rem;">
                        <div>
                            <label for="oldUrl" style="display:block;font-size:0.75rem;font-weight:500;color:#e4e4e7;margin-bottom:0.375rem;">URL Antigua</label>
                            <input type="url" id="oldUrl" wire:model="oldUrl"
                                style="width:100%;background-color:#0a0a0a;color:#e4e4e7;border:1px solid #3f3f46;border-radius:0.375rem;padding:0.5rem 0.75rem;font-size:0.875rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;"
                                placeholder="https://url-antigua.com"
                                required>
                        </div>
                        <div>
                            <label for="newUrl" style="display:block;font-size:0.75rem;font-weight:500;color:#e4e4e7;margin-bottom:0.375rem;">URL Nueva</label>
                            <input type="url" id="newUrl" wire:model="newUrl"
                                style="width:100%;background-color:#0a0a0a;color:#e4e4e7;border:1px solid #3f3f46;border-radius:0.375rem;padding:0.5rem 0.75rem;font-size:0.875rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;"
                                placeholder="https://url-nueva.com"
                                required>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <button type="submit"
                                wire:loading.attr="disabled"
                                wire:target="syncUrls"
                                style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 0.875rem;border-radius:0.375rem;font-size:0.8125rem;font-weight:600;background-color:#8b5cf6;color:#ffffff;border:1px solid #7c3aed;cursor:pointer;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                                onmouseover="this.style.backgroundColor='#7c3aed'"
                                onmouseout="this.style.backgroundColor='#8b5cf6'">
                                <span wire:loading.remove wire:target="syncUrls">Sincronizar URLs y regenerar CSS</span>
                                <span wire:loading wire:target="syncUrls">Procesando…</span>
                            </button>
                        </div>

                        @if ($output)
                            <div>
                                <h4 style="margin:0 0 0.375rem 0;font-size:0.75rem;font-weight:600;color:#e4e4e7;">Salida del comando:</h4>
                                <pre style="margin:0;max-height:24rem;overflow:auto;white-space:pre-wrap;word-break:break-word;font-size:0.6875rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background-color:#0a0a0a;color:#86efac;border:1px solid #27272a;border-radius:0.25rem;padding:0.625rem;">{{ $output }}</pre>
                            </div>
                        @endif
                    </form>
                </div>

                {{-- ==================== WP TABLE PREFIX ==================== --}}
                <div style="border-radius:0.5rem;border:1px solid #27272a;background-color:#18181b;color:#e4e4e7;box-shadow:0 10px 15px -3px rgba(0,0,0,0.3);">
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #27272a;">
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0;color:#c4b5fd;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                            </svg>
                            <h3 style="margin:0;font-size:1rem;font-weight:600;color:#ffffff;">Prefijo de tablas WordPress</h3>
                        </div>
                        <p style="margin:0.125rem 0 0 0;font-size:0.75rem;color:#a1a1aa;">
                            Gestiona el prefijo de las tablas WordPress. Se detecta automáticamente desde <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">wp-config.php</span> o directamente desde la base de datos.
                        </p>
                    </div>

                    <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.75rem;">
                        @foreach ($wordpressContainers as $container)
                            @php
                                $prefixData = $wpPrefixes[$container['id']] ?? ['container_name' => $container['name'], 'prefix' => 'wp_'];
                                $currentPrefix = $prefixData['prefix'] ?? 'wp_';
                                $isRunning = str($container['status'])->contains('running');
                            @endphp
                            <div style="border-radius:0.375rem;border:1px solid #27272a;background-color:#101013;padding:0.75rem 1rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.5rem;">
                                    <div style="min-width:0;display:flex;align-items:center;gap:0.5rem;">
                                        <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:{{ $isRunning ? '#22c55e' : '#ef4444' }};"></span>
                                        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:0.8125rem;font-weight:600;color:#ffffff;">{{ $container['name'] }}</span>
                                        <span style="font-size:0.6875rem;color:#71717a;">({{ $container['status'] }})</span>
                                    </div>
                                </div>
                                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;" x-data="{ prefix: '{{ $currentPrefix }}' }">
                                    <label for="prefix_{{ $container['id'] }}" style="font-size:0.75rem;font-weight:500;color:#e4e4e7;">Prefijo:</label>
                                    <input type="text"
                                        id="prefix_{{ $container['id'] }}"
                                        x-model="prefix"
                                        style="width:8rem;background-color:#0a0a0a;color:#c4b5fd;border:1px solid #3f3f46;border-radius:0.25rem;padding:0.25rem 0.5rem;font-size:0.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;"
                                        pattern="[a-zA-Z0-9_]+"
                                        placeholder="wp_">
                                    <button
                                        type="button"
                                        x-on:click="$wire.updateWpPrefix({{ $container['id'] }}, prefix)"
                                        @if (!$isRunning) disabled @endif
                                        style="padding:0.25rem 0.625rem;border-radius:0.25rem;font-size:0.6875rem;font-weight:600;background-color:{{ $isRunning ? '#8b5cf6' : '#27272a' }};color:#ffffff;border:1px solid {{ $isRunning ? '#7c3aed' : '#3f3f46' }};cursor:{{ $isRunning ? 'pointer' : 'not-allowed' }};{{ $isRunning ? '' : 'opacity:0.5;' }}">
                                        Actualizar
                                    </button>
                                </div>
                                @if (!$isRunning)
                                    <p style="margin:0.375rem 0 0 0;font-size:0.6875rem;color:#fbbf24;">El contenedor debe estar en ejecución para actualizar el prefijo.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- ==================== PHP.INI SETTINGS ==================== --}}
                <div style="border-radius:0.5rem;border:1px solid #27272a;background-color:#18181b;color:#e4e4e7;box-shadow:0 10px 15px -3px rgba(0,0,0,0.3);">
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #27272a;">
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <svg width="16" height="16" style="width:16px;height:16px;flex-shrink:0;color:#c4b5fd;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <h3 style="margin:0;font-size:1rem;font-weight:600;color:#ffffff;">Configuración PHP (php.ini)</h3>
                        </div>
                        <p style="margin:0.125rem 0 0 0;font-size:0.75rem;color:#a1a1aa;">
                            Edita los valores de PHP del contenedor WordPress. Algunos cambios (<span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">memory_limit</span>, <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c4b5fd;">upload_max_filesize</span>) requieren reiniciar el contenedor para aplicarse.
                        </p>
                    </div>

                    <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:1rem;">
                        <div>
                            <label for="php_ini_container" style="display:block;font-size:0.75rem;font-weight:500;color:#e4e4e7;margin-bottom:0.375rem;">Contenedor:</label>
                            <select id="php_ini_container"
                                wire:model.live="selectedContainerForPhpIni"
                                wire:change="loadPhpIniSettings"
                                style="width:100%;background-color:#0a0a0a;color:#e4e4e7;border:1px solid #3f3f46;border-radius:0.375rem;padding:0.5rem 0.75rem;font-size:0.875rem;">
                                <option value="">-- Selecciona un contenedor --</option>
                                @foreach ($wordpressContainers as $container)
                                    <option value="{{ $container['id'] }}" @if (!str($container['status'])->contains('running')) disabled @endif>
                                        {{ $container['name'] }}
                                        @if (!str($container['status'])->contains('running'))
                                            (No está en ejecución)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @if ($selectedContainerForPhpIni && $isLoadingPhpIni)
                            <div style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;color:#a1a1aa;">
                                <svg width="16" height="16" style="width:16px;height:16px;animation:ct-spin 1s linear infinite;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle style="opacity:0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path style="opacity:0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Cargando configuración PHP…
                            </div>
                            @php // Defined in app.blade.php already; redefining here as fallback so the animation works even if the layout style block got stripped. @endphp
                            <style>@keyframes ct-spin { to { transform: rotate(360deg); } }</style>
                        @elseif ($selectedContainerForPhpIni && !empty($phpIniSettings))
                            {{-- "Aplicar defaults WordPress" one-click
                                 button. Calls applyRecommendedPhpDefaults()
                                 which loops over the WP_PHP_INI_DEFAULTS
                                 constant and calls updatePhpIniSetting()
                                 for each. Shows the preset values so the
                                 user knows exactly what they're applying. --}}
                            <div style="border-radius:0.375rem;border:1px solid rgba(139,92,246,0.35);background-color:rgba(139,92,246,0.08);padding:0.75rem 1rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
                                    <div style="min-width:0;flex:1 1 0%;">
                                        <p style="margin:0;font-size:0.8125rem;font-weight:600;color:#c4b5fd;">
                                            Defaults recomendados para WordPress
                                        </p>
                                        <p style="margin:0.25rem 0 0 0;font-size:0.6875rem;color:#a1a1aa;line-height:1.5;">
                                            <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">upload_max_filesize=256M</span> ·
                                            <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">post_max_size=256M</span> ·
                                            <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">memory_limit=512M</span> ·
                                            <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">max_execution_time=300</span> ·
                                            <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">max_input_vars=5000</span>
                                            — sube las stock 2M / 8M a valores listos para uploads grandes, Elementor y plugins de backup.
                                        </p>
                                    </div>
                                    <div style="flex-shrink:0;">
                                        <button
                                            type="button"
                                            wire:click="applyRecommendedPhpDefaults"
                                            wire:loading.attr="disabled"
                                            wire:target="applyRecommendedPhpDefaults"
                                            wire:confirm="¿Aplicar los defaults recomendados para WordPress a este contenedor? Sobrescribe cualquier valor personalizado que tengas ahora. Es seguro ejecutarlo varias veces."
                                            style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 0.875rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background-color:#8b5cf6;color:#ffffff;border:1px solid #7c3aed;cursor:pointer;box-shadow:0 1px 3px rgba(139,92,246,0.4);"
                                            onmouseover="this.style.backgroundColor='#7c3aed'"
                                            onmouseout="this.style.backgroundColor='#8b5cf6'"
                                        >
                                            <svg width="14" height="14" style="width:14px;height:14px;flex-shrink:0;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                            </svg>
                                            <span wire:loading.remove wire:target="applyRecommendedPhpDefaults">Aplicar defaults WordPress</span>
                                            <span wire:loading wire:target="applyRecommendedPhpDefaults">Aplicando…</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(16rem, 1fr));gap:0.75rem;" x-data="{
                                settings: @js($phpIniSettings),
                                updateSetting(setting, value) {
                                    if (!value || String(value).trim() === '') return;
                                    $wire.updatePhpIniSetting(setting, String(value).trim());
                                }
                            }">
                                @foreach ($phpIniSettings as $setting => $value)
                                    <div style="border-radius:0.375rem;border:1px solid #27272a;background-color:#101013;padding:0.75rem 1rem;">
                                        <label for="php_setting_{{ $setting }}" style="display:flex;align-items:center;gap:0.375rem;font-size:0.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#a1a1aa;margin-bottom:0.5rem;">
                                            <svg width="12" height="12" style="width:12px;height:12px;flex-shrink:0;color:#71717a;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>{{ $setting }}</span>
                                        </label>
                                        <input type="text"
                                            id="php_setting_{{ $setting }}"
                                            value="{{ $value }}"
                                            x-model="settings['{{ $setting }}']"
                                            @keydown.enter.prevent="updateSetting('{{ $setting }}', settings['{{ $setting }}'])"
                                            style="width:100%;background-color:#18181b;color:#c4b5fd;border:1px solid #3f3f46;border-radius:0.375rem;padding:0.5rem 0.75rem;font-size:0.8125rem;font-weight:600;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;margin-bottom:0.5rem;"
                                            placeholder="Ej: 200M, 512M, 60…">
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                                            <p style="margin:0;font-size:0.625rem;color:#71717a;">
                                                Actual: <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#a1a1aa;font-weight:600;">{{ $value }}</span>
                                            </p>
                                            <button
                                                type="button"
                                                x-on:click="updateSetting('{{ $setting }}', settings['{{ $setting }}'])"
                                                style="padding:0.25rem 0.625rem;border-radius:0.25rem;font-size:0.6875rem;font-weight:600;background-color:#27272a;color:#c4b5fd;border:1px solid #3f3f46;cursor:pointer;"
                                                onmouseover="this.style.backgroundColor='#3f3f46'"
                                                onmouseout="this.style.backgroundColor='#27272a'">
                                                Actualizar
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div style="padding:0.5rem 0.75rem;border-radius:0.375rem;font-size:0.75rem;background-color:rgba(245,158,11,0.08);color:#fbbf24;border:1px solid rgba(245,158,11,0.25);">
                                <strong>Nota:</strong> Los cambios en memoria (<span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">memory_limit</span>) y tamaños (<span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">upload_max_filesize</span>, <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">post_max_size</span>) suelen requerir reiniciar el contenedor desde el heading para aplicarse completamente.
                            </div>
                        @elseif ($selectedContainerForPhpIni && empty($phpIniSettings))
                            <div style="padding:0.5rem 0.75rem;border-radius:0.375rem;font-size:0.75rem;background-color:rgba(245,158,11,0.08);color:#fcd34d;border:1px solid rgba(245,158,11,0.25);">
                                No se pudieron cargar las configuraciones de PHP. Asegúrate de que el contenedor esté en ejecución.
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
