<div wire:poll.5s="reloadRuns">
    @if (! $available)
        {{-- Defensive: a hand-typed URL on a non-WordPress
             service. The sub-menu link is gated by hasWordPress()
             so this should never be reachable from normal navigation. --}}
        <div class="rounded-lg border p-6 text-sm"
             style="background-color:#101013;border:1px dashed #3f3f46;color:#a1a1aa;">
            <div class="text-sm font-semibold mb-1" style="color:#ffffff;">
                Esta sección solo está disponible para servicios WordPress
            </div>
            <div>
                La generación de copia descargable está pensada para
                stacks que incluyen un contenedor WordPress + base de
                datos. Para Laravel u otros stacks personalizados, usa
                el repositorio de Git correspondiente o un backup
                manual.
            </div>
        </div>
    @else
        <div class="flex flex-col gap-6">
            {{-- Header --}}
            <div>
                <h2 class="text-xl font-bold dark:text-white">Backup y descarga</h2>
                <div class="subtitle">
                    Genera una copia descargable de este sitio WordPress.
                    La copia incluye <span class="font-semibold">todos los
                    archivos</span> de <span class="font-mono">/var/www/html</span>
                    y un <span class="font-semibold">dump completo</span> de la
                    base de datos del sitio, empaquetados en un solo
                    <span class="font-mono">.zip</span> con el nombre del servicio.
                </div>
            </div>

            {{-- Big warning about the temporary nature of these
                 backups. Brutal honesty here so the operator (and
                 the client) never assumes the .zip is going to be
                 there next week. --}}
            <div class="rounded-lg p-4 text-sm"
                 style="background-color:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);color:#fde68a;">
                <div class="flex items-start gap-3">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                         style="width:20px;height:20px;flex-shrink:0;color:#fbbf24;">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 9v2m0 4h.01M10.29 3.86l-8.18 14.18A2 2 0 003.84 21h16.32a2 2 0 001.73-2.96l-8.18-14.18a2 2 0 00-3.42 0z"/>
                    </svg>
                    <div class="min-w-0">
                        <div class="font-semibold mb-1" style="color:#fde68a;">
                            Estas copias son temporales
                        </div>
                        <div style="color:#fcd34d;">
                            Cada copia que generes <span class="font-semibold">se borra
                            automáticamente 30 minutos después</span> de quedar lista
                            para descargar. Descárgala y guárdala en local cuanto antes.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Generate button --}}
            <div class="flex items-center gap-4">
                <button
                    type="button"
                    wire:click="generate"
                    @if($hasRunning) disabled @endif
                    class="button"
                    style="{{ $hasRunning ? 'opacity:0.5;cursor:not-allowed;' : '' }}"
                >
                    @if ($hasRunning)
                        <svg width="14" height="14" style="width:14px;height:14px;display:inline-block;vertical-align:middle;animation:ct-spin 1s linear infinite;margin-right:0.4rem;"
                             fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Generando…
                    @else
                        Generar copia descargable
                    @endif
                </button>
                <span class="text-xs" style="color:#71717a;">
                    Solo puedes tener una copia generándose a la vez por servicio.
                </span>
            </div>

            {{-- Runs table --}}
            <div>
                <h3 class="text-sm font-semibold mb-2 dark:text-white">Historial de copias</h3>

                @if (count($runs) === 0)
                    <div class="rounded-lg p-6 text-sm text-center"
                         style="background-color:#101013;border:1px dashed #3f3f46;color:#71717a;">
                        Todavía no has generado ninguna copia para este servicio.
                    </div>
                @else
                    <div class="rounded-lg overflow-hidden border" style="border-color:#27272a;">
                        <table class="w-full text-xs" style="border-collapse:collapse;">
                            <thead>
                                <tr style="background-color:#101013;color:#a1a1aa;text-align:left;">
                                    <th style="padding:0.5rem 0.75rem;font-weight:600;">Estado</th>
                                    <th style="padding:0.5rem 0.75rem;font-weight:600;">Archivo</th>
                                    <th style="padding:0.5rem 0.75rem;font-weight:600;">Tamaño</th>
                                    <th style="padding:0.5rem 0.75rem;font-weight:600;">Generado</th>
                                    <th style="padding:0.5rem 0.75rem;font-weight:600;">Caduca</th>
                                    <th style="padding:0.5rem 0.75rem;font-weight:600;text-align:right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($runs as $r)
                                    <tr style="border-top:1px solid #27272a;background-color:#18181b;color:#e4e4e7;">
                                        <td style="padding:0.5rem 0.75rem;vertical-align:top;">
                                            @if ($r['status'] === 'running' || $r['status'] === 'pending')
                                                <span style="display:inline-block;border-radius:0.25rem;padding:0.125rem 0.5rem;font-size:0.625rem;font-weight:700;text-transform:uppercase;background-color:rgba(245,158,11,0.15);color:#fbbf24;border:1px solid rgba(245,158,11,0.3);">
                                                    {{ $r['status'] === 'pending' ? 'EN COLA' : 'GENERANDO' }}
                                                </span>
                                            @elseif ($r['status'] === 'completed')
                                                <span style="display:inline-block;border-radius:0.25rem;padding:0.125rem 0.5rem;font-size:0.625rem;font-weight:700;text-transform:uppercase;background-color:rgba(34,197,94,0.15);color:#4ade80;border:1px solid rgba(34,197,94,0.3);">
                                                    COMPLETADO
                                                </span>
                                            @elseif ($r['status'] === 'failed')
                                                <span style="display:inline-block;border-radius:0.25rem;padding:0.125rem 0.5rem;font-size:0.625rem;font-weight:700;text-transform:uppercase;background-color:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.3);">
                                                    FALLIDO
                                                </span>
                                            @else
                                                <span style="display:inline-block;border-radius:0.25rem;padding:0.125rem 0.5rem;font-size:0.625rem;font-weight:700;text-transform:uppercase;background-color:rgba(113,113,122,0.15);color:#a1a1aa;border:1px solid rgba(113,113,122,0.3);">
                                                    {{ strtoupper($r['status']) }}
                                                </span>
                                            @endif

                                            @if ($r['show_error'] && $r['last_message'])
                                                <div style="margin-top:0.4rem;font-size:0.625rem;color:#f87171;max-width:280px;">
                                                    {{ $r['last_message'] }}
                                                </div>
                                            @elseif ($r['last_message'] && in_array($r['status'], ['running', 'pending'], true))
                                                <div style="margin-top:0.4rem;font-size:0.625rem;color:#a1a1aa;max-width:280px;">
                                                    {{ $r['last_message'] }}
                                                </div>
                                            @endif
                                        </td>
                                        <td style="padding:0.5rem 0.75rem;vertical-align:top;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
                                            {{ $r['archive_name'] ?? '—' }}
                                        </td>
                                        <td style="padding:0.5rem 0.75rem;vertical-align:top;">
                                            @if ($r['size_bytes'])
                                                @php
                                                    $bytes = (int) $r['size_bytes'];
                                                    if ($bytes < 1024) { $h = $bytes.' B'; }
                                                    elseif ($bytes < 1024 * 1024) { $h = round($bytes / 1024, 1).' KB'; }
                                                    elseif ($bytes < 1024 * 1024 * 1024) { $h = round($bytes / 1024 / 1024, 1).' MB'; }
                                                    else { $h = round($bytes / 1024 / 1024 / 1024, 2).' GB'; }
                                                @endphp
                                                {{ $h }}
                                                @if ($r['duration_seconds'] !== null)
                                                    <div style="font-size:0.625rem;color:#71717a;">{{ $r['duration_seconds'] }}s</div>
                                                @endif
                                            @else
                                                <span style="color:#52525b;">—</span>
                                            @endif
                                        </td>
                                        <td style="padding:0.5rem 0.75rem;vertical-align:top;color:#a1a1aa;">
                                            <div title="{{ $r['created_at_iso'] }}">{{ $r['created_at_h'] }}</div>
                                        </td>
                                        <td style="padding:0.5rem 0.75rem;vertical-align:top;color:#a1a1aa;">
                                            @if ($r['expires_in_human'] && $r['status'] === 'completed')
                                                <div title="{{ $r['expires_at_iso'] }}">{{ $r['expires_in_human'] }}</div>
                                            @elseif ($r['status'] === 'expired')
                                                <span style="color:#71717a;">caducado</span>
                                            @else
                                                <span style="color:#52525b;">—</span>
                                            @endif
                                        </td>
                                        <td style="padding:0.5rem 0.75rem;vertical-align:top;text-align:right;">
                                            @if ($r['is_downloadable'])
                                                <a href="{{ $r['download_url'] }}"
                                                   class="button"
                                                   style="background-color:#16a34a;border-color:#15803d;color:#ffffff;padding:0.25rem 0.6rem;font-size:0.625rem;text-decoration:none;display:inline-block;">
                                                    Descargar
                                                </a>
                                            @endif
                                            <button type="button"
                                                    wire:click="deleteRun({{ $r['id'] }})"
                                                    wire:confirm="¿Eliminar esta entrada del historial?"
                                                    style="background:none;border:1px solid #3f3f46;color:#a1a1aa;border-radius:0.25rem;padding:0.25rem 0.5rem;font-size:0.625rem;margin-left:0.4rem;cursor:pointer;">
                                                Quitar
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
