<div
    @if (! $paused)
        wire:poll.{{ $pollMillis }}ms="tick"
    @endif
>
    <x-slot:title>
        Monitor | Coolify
    </x-slot>

    {{-- Everything in this view uses inline styles for colour
         and sizing. That is deliberate: the fork has been bitten
         twice before by Tailwind utility classes that were not
         in the compiled bundle on the production server. The
         Monitor page is a place the operator reaches when
         something is ALREADY on fire — the last thing we want
         is the UI to render broken on that very page. So every
         colour, border, padding, size and animation lives
         inline, survives a missing Tailwind rebuild, and never
         depends on arbitrary values.

         Keyframes are scoped under the mon-* prefix to avoid
         colliding with Tailwind's built-in animations even if
         both end up defined. --}}
    <style>
        @keyframes mon-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.55; }
        }
        @keyframes mon-spin {
            to { transform: rotate(360deg); }
        }
    </style>

    {{-- Header.

         Structured as TWO stacked rows instead of the original
         single flex row so the right-aligned Pausar/Actualizar
         buttons do not collide with the absolutely-positioned
         topbar dropdowns (alerts bell + compression tasks) that
         live in the same top-right corner of the main content
         area. The title row explicitly reserves right-padding
         equal to the combined width of those topbar buttons so
         they never overlap, while the actions row flows below on
         its own line with no right-padding so the counts pill +
         pause + refresh get all the horizontal space they need. --}}
    <div style="padding-right:17rem;margin-bottom:0.9rem;">
        <h1 style="font-size:1.5rem;font-weight:700;color:#ffffff;margin:0;">Monitor del sistema</h1>
        <div style="margin-top:0.25rem;font-size:0.8125rem;color:#a1a1aa;">
            Estado en vivo de servidores y recursos gestionados por este Coolify.
            @if ($paused)
                <span style="color:#fbbf24;font-weight:600;">(actualización pausada)</span>
            @else
                Se actualiza cada {{ (int) round($pollMillis / 1000) }}s.
            @endif
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.25rem;">
        <div style="display:flex;gap:0.75rem;align-items:center;padding:0.4rem 0.75rem;border-radius:0.375rem;background-color:#18181b;border:1px solid #27272a;">
            <div style="display:flex;align-items:center;gap:0.4rem;">
                <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:#22c55e;"></span>
                <span style="font-size:0.75rem;color:#e4e4e7;font-weight:600;">{{ $counts['ok'] }}</span>
                <span style="font-size:0.6875rem;color:#71717a;">OK</span>
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;">
                <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:#f59e0b;"></span>
                <span style="font-size:0.75rem;color:#e4e4e7;font-weight:600;">{{ $counts['warning'] }}</span>
                <span style="font-size:0.6875rem;color:#71717a;">warn</span>
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;">
                <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:#ef4444;"></span>
                <span style="font-size:0.75rem;color:#e4e4e7;font-weight:600;">{{ $counts['critical'] }}</span>
                <span style="font-size:0.6875rem;color:#71717a;">crit</span>
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;">
                <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:#52525b;"></span>
                <span style="font-size:0.75rem;color:#e4e4e7;font-weight:600;">{{ $counts['stopped'] }}</span>
                <span style="font-size:0.6875rem;color:#71717a;">stop</span>
            </div>
        </div>
        <button type="button" wire:click="togglePause"
            style="padding:0.4rem 0.75rem;font-size:0.75rem;font-weight:600;border-radius:0.375rem;border:1px solid #3f3f46;background-color:{{ $paused ? 'rgba(251,191,36,0.15)' : '#18181b' }};color:{{ $paused ? '#fbbf24' : '#e4e4e7' }};cursor:pointer;">
            @if ($paused)
                ▶ Reanudar
            @else
                ⏸ Pausar
            @endif
        </button>
        <button type="button" wire:click="forceRefresh"
            style="padding:0.4rem 0.75rem;font-size:0.75rem;font-weight:600;border-radius:0.375rem;border:1px solid #3f3f46;background-color:#18181b;color:#c4b5fd;cursor:pointer;"
            wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="forceRefresh,tick">↻ Actualizar</span>
            <span wire:loading wire:target="forceRefresh,tick" style="display:inline-block;animation:mon-spin 1s linear infinite;">↻</span>
        </button>
    </div>

    {{-- Disk pressure banner (conditional) --}}
    @if ($diskPressure)
        <div style="margin-bottom:1.25rem;padding:0.9rem 1rem;border-radius:0.5rem;background-color:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.35);color:#fca5a5;display:flex;align-items:flex-start;gap:0.75rem;">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:#f87171;flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 9v2m0 4h.01M10.29 3.86l-8.18 14.18A2 2 0 003.84 21h16.32a2 2 0 001.73-2.96l-8.18-14.18a2 2 0 00-3.42 0z"/>
            </svg>
            <div style="min-width:0;">
                <div style="font-weight:700;color:#fca5a5;">Espacio en disco bajo</div>
                <div style="font-size:0.8125rem;color:#fecaca;margin-top:0.1rem;">{{ $diskPressureMessage }}</div>
            </div>
        </div>
    @endif

    {{-- Server cards — each card takes the FULL content width
         and lays its CPU / RAM / Disk bars horizontally in a
         3-column grid inside the card. Before this rewrite the
         cards were capped at minmax(22rem, 1fr) which on a
         single-server install left ~70% of the row empty with
         a narrow metrics column on the left. Now each card
         stretches across the available width and the three
         bars share that space evenly. --}}
    <h2 style="font-size:1rem;font-weight:700;color:#ffffff;margin:0 0 0.75rem 0;">Servidores</h2>
    @if (count($serverSnapshots) === 0)
        <div style="padding:2rem;text-align:center;border:1px dashed #3f3f46;border-radius:0.5rem;color:#71717a;">
            No hay servidores registrados en este Coolify.
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:0.875rem;margin-bottom:1.75rem;">
            @foreach ($serverSnapshots as $s)
                @php
                    $cpu = $s['cpu_percent'];
                    $mem = $s['mem_percent'];
                    $disk = $s['disk_percent'];
                    $online = $s['online'];
                    $statusColor = $online ? '#22c55e' : '#ef4444';
                    $borderColor = $online ? 'rgba(34,197,94,0.35)' : 'rgba(239,68,68,0.35)';
                    $proxyRunning = str_contains(strtolower((string) ($s['proxy_status'] ?? '')), 'running');
                    $proxyBad = in_array(strtolower((string) ($s['proxy_status'] ?? '')), ['exited', 'error', 'stopping', 'unknown'], true);
                    $proxyColor = $proxyRunning ? '#22c55e' : ($proxyBad ? '#ef4444' : '#fbbf24');
                @endphp
                <div style="padding:1rem 1.25rem;border-radius:0.5rem;background-color:#18181b;border:1px solid {{ $borderColor }};">
                    {{-- Header row: name/ip/uptime (left) and
                         containers count (right) --}}
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.75rem;flex-wrap:wrap;">
                        <div style="min-width:0;flex:1 1 0%;">
                            <div style="display:flex;align-items:center;gap:0.5rem;">
                                <span style="display:inline-block;width:0.625rem;height:0.625rem;border-radius:9999px;background-color:{{ $statusColor }};{{ $online ? '' : 'animation:mon-pulse 2s ease-in-out infinite;' }}"></span>
                                <span style="font-weight:700;color:#ffffff;font-size:1rem;">{{ $s['server_name'] }}</span>
                                <span style="font-size:0.6875rem;color:#71717a;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">· {{ $s['server_ip'] }}</span>
                                @if ($s['uptime_human'])
                                    <span style="font-size:0.6875rem;color:#71717a;">· up {{ $s['uptime_human'] }}</span>
                                @endif
                                @if ($s['load_avg'] !== null)
                                    <span style="font-size:0.6875rem;color:#71717a;">· load {{ $s['load_avg'] }}</span>
                                @endif
                            </div>
                        </div>
                        @if ($online && $s['containers_running'] !== null)
                            <div style="text-align:right;flex-shrink:0;">
                                <span style="font-size:1.125rem;font-weight:700;color:#ffffff;">{{ $s['containers_running'] }}</span>
                                <span style="font-size:0.6875rem;color:#71717a;">/ {{ $s['containers_total'] ?? '?' }} contenedores</span>
                            </div>
                        @endif
                    </div>

                    @if (! $online)
                        <div style="padding:0.5rem 0.75rem;border-radius:0.25rem;background-color:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;font-size:0.75rem;">
                            {{ $s['error'] ?? 'Sin conexión con el servidor.' }}
                        </div>
                    @else
                        {{-- Metric bars: 3-column grid, one bar
                             per column, horizontally filling the
                             whole card width. Collapses to 1
                             column under 32rem width via the
                             auto-fit + minmax. --}}
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem 1.5rem;">
                            @include('livewire.monitor._metric_bar', ['label' => 'CPU', 'value' => $cpu, 'suffix' => '%'])
                            @include('livewire.monitor._metric_bar', ['label' => 'RAM', 'value' => $mem, 'suffix' => '%', 'extra' => ($s['mem_used_human'] ?? '').' / '.($s['mem_total_human'] ?? '')])
                            @include('livewire.monitor._metric_bar', ['label' => 'Disco', 'value' => $disk, 'suffix' => '%', 'extra' => ($s['disk_used_human'] ?? '').' / '.($s['disk_total_human'] ?? '')])
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.9rem;padding-top:0.75rem;border-top:1px solid #27272a;gap:0.5rem;flex-wrap:wrap;">
                            <div style="display:flex;align-items:center;gap:0.4rem;min-width:0;">
                                <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:{{ $proxyColor }};"></span>
                                <span style="font-size:0.6875rem;color:#a1a1aa;">Proxy {{ $s['proxy_type'] ?: '—' }}</span>
                                <span style="font-size:0.625rem;color:#71717a;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $s['proxy_status'] }}</span>
                            </div>
                            <button type="button"
                                wire:click="restartProxyOnServer({{ (int) $s['server_id'] }})"
                                wire:confirm="¿Reiniciar el proxy Traefik de {{ $s['server_name'] }}? Los sitios con dominio tendrán ~5 segundos de downtime."
                                style="padding:0.3rem 0.7rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;border:1px solid rgba(196,181,253,0.35);background-color:rgba(139,92,246,0.12);color:#c4b5fd;cursor:pointer;">
                                Reiniciar proxy
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Resource toolbar — filter pills + per-page selector
         live side by side on the same row. Bulk actions sit on
         their own row directly below but only render when the
         operator has actually selected something. --}}
    @php
        $selectedCount = count($selected);
        // Helper closures for sort arrow rendering — keeps the
        // <th> markup below from ballooning.
        $sortArrow = function (string $col) use ($sortBy, $sortDir) {
            if ($sortBy !== $col) {
                return '↕';
            }
            return $sortDir === 'asc' ? '▲' : '▼';
        };
        $sortActive = fn (string $col) => $sortBy === $col ? '#ffffff' : '#a1a1aa';
    @endphp
    <div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 0.75rem 0;gap:0.75rem;flex-wrap:wrap;">
        <h2 style="font-size:1rem;font-weight:700;color:#ffffff;margin:0;">Recursos</h2>
        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            {{-- Filter pills --}}
            <div style="display:flex;gap:0.25rem;padding:0.25rem;border-radius:0.375rem;background-color:#18181b;border:1px solid #27272a;">
                <button type="button" wire:click="setFilter('all')"
                    style="padding:0.3rem 0.7rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;border:0;cursor:pointer;background-color:{{ $filter === 'all' ? '#27272a' : 'transparent' }};color:{{ $filter === 'all' ? '#ffffff' : '#a1a1aa' }};">
                    Todos ({{ $counts['total'] }})
                </button>
                <button type="button" wire:click="setFilter('issues')"
                    style="padding:0.3rem 0.7rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;border:0;cursor:pointer;background-color:{{ $filter === 'issues' ? 'rgba(239,68,68,0.15)' : 'transparent' }};color:{{ $filter === 'issues' ? '#fca5a5' : '#a1a1aa' }};">
                    Solo con problemas ({{ $counts['critical'] + $counts['warning'] }})
                </button>
            </div>
            {{-- Per-page selector --}}
            <div style="display:flex;gap:0.25rem;padding:0.25rem;border-radius:0.375rem;background-color:#18181b;border:1px solid #27272a;">
                <span style="padding:0.3rem 0.4rem;font-size:0.625rem;color:#71717a;align-self:center;">por página:</span>
                @foreach ([10, 20, 0] as $pp)
                    <button type="button" wire:click="setPerPage({{ $pp }})"
                        style="padding:0.3rem 0.55rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;border:0;cursor:pointer;background-color:{{ $perPage === $pp ? '#27272a' : 'transparent' }};color:{{ $perPage === $pp ? '#ffffff' : '#a1a1aa' }};">
                        {{ $pp === 0 ? 'Todos' : $pp }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Bulk action bar: only rendered when at least one row
         is selected. Gives the operator one-click
         redeploy/restart/stop on the whole selection, plus
         "clear selection" to bail out without doing anything. --}}
    @if ($selectedCount > 0)
        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem;padding:0.7rem 0.9rem;border-radius:0.5rem;background-color:rgba(139,92,246,0.08);border:1px solid rgba(139,92,246,0.35);">
            <div style="font-size:0.8125rem;font-weight:700;color:#c4b5fd;">
                {{ $selectedCount }} {{ $selectedCount === 1 ? 'recurso seleccionado' : 'recursos seleccionados' }}
            </div>
            <div style="flex:1 1 0%;"></div>
            <button type="button"
                wire:click="bulkAction('redeploy')"
                wire:confirm="¿Redeploy de los {{ $selectedCount }} recursos seleccionados? Se re-aplica compose y env vars."
                style="padding:0.35rem 0.7rem;font-size:0.6875rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(139,92,246,0.4);background-color:rgba(139,92,246,0.2);color:#c4b5fd;cursor:pointer;">
                ⟳ Redeploy ({{ $selectedCount }})
            </button>
            <button type="button"
                wire:click="bulkAction('restart')"
                style="padding:0.35rem 0.7rem;font-size:0.6875rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(96,165,250,0.4);background-color:rgba(59,130,246,0.2);color:#60a5fa;cursor:pointer;">
                ↻ Restart ({{ $selectedCount }})
            </button>
            <button type="button"
                wire:click="bulkAction('stop')"
                wire:confirm="¿Parar los {{ $selectedCount }} recursos seleccionados? Dejarán de responder hasta que los arranques de nuevo."
                style="padding:0.35rem 0.7rem;font-size:0.6875rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(239,68,68,0.4);background-color:rgba(239,68,68,0.2);color:#f87171;cursor:pointer;">
                ■ Stop ({{ $selectedCount }})
            </button>
            <button type="button"
                wire:click="clearSelection"
                style="padding:0.35rem 0.7rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;border:1px solid #3f3f46;background-color:transparent;color:#a1a1aa;cursor:pointer;">
                Limpiar selección
            </button>
        </div>
    @endif

    @if (count($resources) === 0)
        <div style="padding:2rem;text-align:center;border:1px dashed #3f3f46;border-radius:0.5rem;color:#71717a;">
            @if ($filter === 'issues')
                Todo está corriendo como debería. 🎉
            @else
                No hay recursos desplegados en ningún servidor.
            @endif
        </div>
    @else
        <div style="border:1px solid #27272a;border-radius:0.5rem;overflow:hidden;margin-bottom:0.75rem;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="background-color:#101013;color:#a1a1aa;text-align:left;">
                        {{-- Checkbox column header with select-all button --}}
                        <th style="padding:0.6rem 0.6rem 0.6rem 0.75rem;width:1.5rem;vertical-align:middle;">
                            <button type="button" wire:click="selectAllVisible"
                                title="Seleccionar todos los visibles"
                                style="padding:0.15rem 0.35rem;font-size:0.625rem;font-weight:700;border-radius:0.2rem;border:1px solid #3f3f46;background-color:#18181b;color:#a1a1aa;cursor:pointer;">
                                ☑
                            </button>
                        </th>
                        {{-- Sortable: Estado (severity) --}}
                        <th style="padding:0.6rem 0.75rem;font-weight:600;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;cursor:pointer;color:{{ $sortActive('severity') }};" wire:click="setSortBy('severity')">
                            Estado {{ $sortArrow('severity') }}
                        </th>
                        {{-- Sortable: Nombre --}}
                        <th style="padding:0.6rem 0.75rem;font-weight:600;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;cursor:pointer;color:{{ $sortActive('name') }};" wire:click="setSortBy('name')">
                            Nombre {{ $sortArrow('name') }}
                        </th>
                        {{-- Sortable: Tipo (kind_label) --}}
                        <th style="padding:0.6rem 0.75rem;font-weight:600;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;cursor:pointer;color:{{ $sortActive('kind_label') }};" wire:click="setSortBy('kind_label')">
                            Tipo {{ $sortArrow('kind_label') }}
                        </th>
                        <th style="padding:0.6rem 0.75rem;font-weight:600;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;">Servidor</th>
                        <th style="padding:0.6rem 0.75rem;font-weight:600;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;">Último online</th>
                        <th style="padding:0.6rem 0.75rem;font-weight:600;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resources as $r)
                        @php
                            $sev = $r['severity'];
                            $selKey = $r['type'].':'.$r['uuid'];
                            $isSelected = isset($selected[$selKey]);
                            $borderLeft = match ($sev) {
                                'critical' => '3px solid #ef4444',
                                'warning' => '3px solid #f59e0b',
                                default => '3px solid #22c55e',
                            };
                            $statusBg = match ($sev) {
                                'critical' => 'rgba(239,68,68,0.15)',
                                'warning' => 'rgba(245,158,11,0.15)',
                                default => 'rgba(34,197,94,0.12)',
                            };
                            $statusFg = match ($sev) {
                                'critical' => '#f87171',
                                'warning' => '#fbbf24',
                                default => '#4ade80',
                            };
                            $statusBorder = match ($sev) {
                                'critical' => 'rgba(239,68,68,0.3)',
                                'warning' => 'rgba(245,158,11,0.3)',
                                default => 'rgba(34,197,94,0.3)',
                            };
                            $rowBg = $isSelected ? 'rgba(139,92,246,0.08)' : '#18181b';
                        @endphp
                        <tr style="background-color:{{ $rowBg }};border-top:1px solid #27272a;border-left:{{ $borderLeft }};color:#e4e4e7;">
                            {{-- Checkbox --}}
                            <td style="padding:0.55rem 0.6rem 0.55rem 0.75rem;vertical-align:middle;">
                                <input type="checkbox"
                                    wire:click="toggleSelection('{{ $selKey }}')"
                                    @if ($isSelected) checked @endif
                                    style="cursor:pointer;width:0.95rem;height:0.95rem;accent-color:#8b5cf6;">
                            </td>
                            <td style="padding:0.55rem 0.75rem;vertical-align:middle;">
                                <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:0.25rem;font-size:0.625rem;font-weight:700;text-transform:uppercase;background-color:{{ $statusBg }};color:{{ $statusFg }};border:1px solid {{ $statusBorder }};font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
                                    {{ $r['status'] }}
                                </span>
                            </td>
                            <td style="padding:0.55rem 0.75rem;vertical-align:middle;">
                                <a href="{{ $r['url'] }}" wire:navigate style="color:#ffffff;font-weight:600;text-decoration:none;">{{ $r['name'] }}</a>
                                @if ($r['project_name'])
                                    <div style="font-size:0.625rem;color:#71717a;margin-top:0.1rem;">{{ $r['project_name'] }} / {{ $r['environment_name'] }}</div>
                                @endif
                            </td>
                            <td style="padding:0.55rem 0.75rem;vertical-align:middle;color:#a1a1aa;">
                                <span style="display:inline-block;padding:0.05rem 0.4rem;border-radius:0.25rem;font-size:0.625rem;font-weight:600;background-color:rgba(139,92,246,0.12);color:#c4b5fd;border:1px solid rgba(139,92,246,0.3);">
                                    {{ $r['kind_label'] }}
                                </span>
                            </td>
                            <td style="padding:0.55rem 0.75rem;vertical-align:middle;color:#a1a1aa;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:0.6875rem;">
                                {{ $r['server_name'] }}
                            </td>
                            <td style="padding:0.55rem 0.75rem;vertical-align:middle;color:#71717a;font-size:0.6875rem;">
                                {{ $r['last_online_human'] ?? '—' }}
                            </td>
                            <td style="padding:0.55rem 0.75rem;vertical-align:middle;text-align:right;white-space:nowrap;">
                                {{-- Navigation buttons first, then
                                     action buttons. Abrir always
                                     links to the resource config
                                     page (same as clicking the
                                     name). Ver web only renders
                                     when there is a real fqdn, and
                                     opens in a new tab. --}}
                                <a href="{{ $r['url'] }}" wire:navigate
                                    title="Abrir configuración"
                                    style="display:inline-block;padding:0.25rem 0.5rem;font-size:0.625rem;font-weight:700;border-radius:0.25rem;border:1px solid #3f3f46;background-color:#18181b;color:#a1a1aa;cursor:pointer;text-decoration:none;">
                                    ↗ Abrir
                                </a>
                                @if (! empty($r['web_url']))
                                    <a href="{{ $r['web_url'] }}" target="_blank" rel="noopener noreferrer"
                                        title="Ver la web del servicio en una pestaña nueva"
                                        style="display:inline-block;padding:0.25rem 0.5rem;font-size:0.625rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(34,197,94,0.4);background-color:rgba(34,197,94,0.12);color:#4ade80;cursor:pointer;text-decoration:none;margin-left:0.25rem;">
                                        🌐 Ver web
                                    </a>
                                @endif
                                @if ($r['is_stopped'])
                                    <button type="button"
                                        wire:click="startResource('{{ $r['type'] }}', '{{ $r['uuid'] }}')"
                                        title="Arrancar"
                                        style="display:inline-block;padding:0.25rem 0.55rem;font-size:0.625rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(34,197,94,0.4);background-color:rgba(34,197,94,0.15);color:#4ade80;cursor:pointer;margin-left:0.25rem;">
                                        ▶ Start
                                    </button>
                                @else
                                    <button type="button"
                                        wire:click="redeployResource('{{ $r['type'] }}', '{{ $r['uuid'] }}')"
                                        wire:confirm="¿Redeploy de {{ $r['name'] }}? Se re-aplica compose y env vars (downtime ~30s)."
                                        title="Redeploy (full pipeline)"
                                        style="display:inline-block;padding:0.25rem 0.55rem;font-size:0.625rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(139,92,246,0.4);background-color:rgba(139,92,246,0.15);color:#c4b5fd;cursor:pointer;margin-left:0.25rem;">
                                        ⟳ Redeploy
                                    </button>
                                    <button type="button"
                                        wire:click="restartResource('{{ $r['type'] }}', '{{ $r['uuid'] }}')"
                                        title="Restart (docker restart, sin rebuild ni redeploy)"
                                        style="display:inline-block;padding:0.25rem 0.55rem;font-size:0.625rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(96,165,250,0.4);background-color:rgba(59,130,246,0.15);color:#60a5fa;cursor:pointer;margin-left:0.25rem;">
                                        ↻ Restart
                                    </button>
                                    <button type="button"
                                        wire:click="stopResource('{{ $r['type'] }}', '{{ $r['uuid'] }}')"
                                        wire:confirm="¿Parar {{ $r['name'] }}? El servicio dejará de responder hasta que lo arranques de nuevo."
                                        title="Stop"
                                        style="display:inline-block;padding:0.25rem 0.55rem;font-size:0.625rem;font-weight:700;border-radius:0.25rem;border:1px solid rgba(239,68,68,0.4);background-color:rgba(239,68,68,0.15);color:#f87171;cursor:pointer;margin-left:0.25rem;">
                                        ■ Stop
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination bar (only when perPage > 0 and there's
             more than one page). "1-10 de 55" left-aligned,
             prev/page-number/next right-aligned. --}}
        @if ($perPage > 0 && $totalRows > $perPage)
            @php
                $maxPage = max(1, (int) ceil($totalRows / $perPage));
                $from = (($page - 1) * $perPage) + 1;
                $to = min($page * $perPage, $totalRows);
            @endphp
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:1.75rem;padding:0.5rem 0.9rem;border-radius:0.375rem;background-color:#18181b;border:1px solid #27272a;font-size:0.75rem;color:#a1a1aa;flex-wrap:wrap;">
                <span>{{ $from }}–{{ $to }} de {{ $totalRows }}</span>
                <div style="display:flex;gap:0.4rem;align-items:center;">
                    <button type="button" wire:click="prevPage" @if ($page <= 1) disabled @endif
                        style="padding:0.25rem 0.6rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;border:1px solid #3f3f46;background-color:#101013;color:{{ $page <= 1 ? '#3f3f46' : '#e4e4e7' }};cursor:{{ $page <= 1 ? 'not-allowed' : 'pointer' }};">
                        ← Anterior
                    </button>
                    <span style="padding:0.25rem 0.6rem;font-size:0.6875rem;font-weight:600;color:#c4b5fd;">Página {{ $page }} de {{ $maxPage }}</span>
                    <button type="button" wire:click="nextPage" @if ($page >= $maxPage) disabled @endif
                        style="padding:0.25rem 0.6rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;border:1px solid #3f3f46;background-color:#101013;color:{{ $page >= $maxPage ? '#3f3f46' : '#e4e4e7' }};cursor:{{ $page >= $maxPage ? 'not-allowed' : 'pointer' }};">
                        Siguiente →
                    </button>
                </div>
            </div>
        @else
            <div style="margin-bottom:1.75rem;"></div>
        @endif
    @endif

    {{-- Activity feed --}}
    <h2 style="font-size:1rem;font-weight:700;color:#ffffff;margin:0 0 0.75rem 0;">Actividad reciente</h2>
    @if (count($activity) === 0)
        <div style="padding:1.25rem;text-align:center;border:1px dashed #3f3f46;border-radius:0.5rem;color:#71717a;font-size:0.8125rem;">
            Aún no hay actividad registrada.
        </div>
    @else
        <div style="border:1px solid #27272a;border-radius:0.5rem;overflow:hidden;">
            @foreach ($activity as $a)
                @php
                    $dotColor = match ($a['severity']) {
                        'critical' => '#ef4444',
                        'warning' => '#f59e0b',
                        'ok' => '#22c55e',
                        default => '#71717a',
                    };
                @endphp
                <div style="display:flex;align-items:flex-start;gap:0.75rem;padding:0.6rem 0.9rem;background-color:#18181b;border-top:1px solid #27272a;">
                    <span style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:{{ $dotColor }};margin-top:0.4rem;flex-shrink:0;"></span>
                    <div style="min-width:0;flex:1 1 0%;">
                        <div style="font-size:0.8125rem;color:#e4e4e7;word-break:break-word;">{{ $a['label'] }}</div>
                        @if ($a['status'])
                            <div style="font-size:0.6875rem;color:#71717a;margin-top:0.1rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $a['status'] }}</div>
                        @endif
                    </div>
                    <div style="font-size:0.6875rem;color:#71717a;flex-shrink:0;white-space:nowrap;">{{ $a['when_human'] }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
