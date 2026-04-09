<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Clientes | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    {{-- No sub-menu: Clientes is a top-level tab in the service
         heading (next to Files), same pattern as Logs / Terminal /
         Files. Users can navigate to any other service section from
         the heading row that is already rendered above. --}}
    <div class="w-full min-w-0 flex flex-col gap-6">
            <div>
                <h2 class="text-xl font-bold dark:text-white">Clientes con acceso a este proyecto</h2>
                <div class="subtitle">
                    Los clientes marcados pueden ver <span class="font-semibold">todos</span>
                    los servicios del proyecto <span class="font-mono" style="color:#c4b5fd;">{{ $project->name }}</span>,
                    no solo este. El permiso se aplica a nivel de proyecto (pivot
                    <span class="font-mono">project_user</span>), así que desmarcar aquí revoca el acceso
                    a todos los servicios hermanos también.
                </div>
            </div>

            @if ($availableClients->isEmpty())
                {{-- Empty state: no clients in the team yet. Point the
                     admin at the Users page where they can create one,
                     instead of silently rendering an empty list. --}}
                <div
                    class="rounded-lg border p-6 text-sm"
                    style="background-color:#101013;border:1px dashed #3f3f46;color:#a1a1aa;"
                >
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#fbbf24;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold mb-1" style="color:#ffffff;">
                                Aún no tienes ningún cliente creado
                            </div>
                            <p class="text-xs leading-relaxed">
                                Los clientes son usuarios con acceso restringido a una lista
                                concreta de proyectos — no ven el resto de Coolify.
                                Crea uno desde
                                <a
                                    href="{{ route('users.create') }}"
                                    class="underline"
                                    style="color:#c4b5fd;"
                                    {{ wireNavigate() }}
                                >Users → Crear usuario</a>
                                marcando la casilla "Es cliente", y vuelve aquí para asignarle este proyecto.
                            </p>
                        </div>
                    </div>
                </div>
            @else
                <div
                    class="rounded-lg border shadow-lg"
                    style="background-color:#18181b;border-color:#27272a;color:#e4e4e7;"
                >
                    <div class="px-5 py-4 border-b flex items-center justify-between gap-2" style="border-color:#27272a;">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold" style="color:#ffffff;">
                                {{ $availableClients->count() }} {{ $availableClients->count() === 1 ? 'cliente disponible' : 'clientes disponibles' }}
                            </h3>
                            <p class="mt-0.5 text-xs" style="color:#a1a1aa;">
                                Marca los que deben poder acceder a
                                <span class="font-mono" style="color:#c4b5fd;">{{ $project->name }}</span>.
                            </p>
                        </div>
                        @if ($this->isDirty)
                            <span
                                class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-[11px] font-semibold"
                                style="background-color:rgba(245,158,11,0.12);color:#fcd34d;border:1px solid rgba(245,158,11,0.35);"
                            >
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                Cambios sin guardar
                            </span>
                        @endif
                    </div>

                    <ul class="divide-y" style="border-color:#27272a;">
                        @foreach ($availableClients as $client)
                            <li>
                                <label
                                    for="client_{{ $client->id }}"
                                    class="flex items-center gap-3 px-5 py-3 cursor-pointer transition-colors"
                                    onmouseover="this.style.backgroundColor='#101013'"
                                    onmouseout="this.style.backgroundColor='transparent'"
                                >
                                    <input
                                        type="checkbox"
                                        id="client_{{ $client->id }}"
                                        value="{{ $client->id }}"
                                        wire:model.live="assignedClientIds"
                                        class="w-4 h-4 rounded cursor-pointer"
                                        style="accent-color:#8b5cf6;"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="font-semibold text-sm" style="color:#ffffff;">
                                            {{ $client->name }}
                                        </div>
                                        <div class="text-xs font-mono truncate" style="color:#a1a1aa;">
                                            {{ $client->email }}
                                        </div>
                                    </div>
                                    @if (in_array($client->id, $assignedClientIds, true))
                                        <span
                                            class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-mono"
                                            style="background-color:rgba(34,197,94,0.12);color:#86efac;border:1px solid rgba(34,197,94,0.35);"
                                        >
                                            acceso concedido
                                        </span>
                                    @else
                                        <span
                                            class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-mono"
                                            style="background-color:#27272a;color:#71717a;border:1px solid #3f3f46;"
                                        >
                                            sin acceso
                                        </span>
                                    @endif
                                </label>
                            </li>
                        @endforeach
                    </ul>

                    <div class="px-5 py-4 border-t flex flex-wrap items-center gap-2" style="border-color:#27272a;">
                        <x-forms.button
                            wire:click="save"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            wire:confirm="¿Guardar los cambios de acceso? Los clientes desmarcados perderán el acceso al proyecto inmediatamente."
                        >
                            <span wire:loading.remove wire:target="save">Guardar cambios</span>
                            <span wire:loading wire:target="save">Guardando…</span>
                        </x-forms.button>
                        <a
                            href="{{ route('users.index') }}"
                            class="rounded px-3 py-1.5 text-xs font-semibold transition-colors"
                            style="background-color:#27272a;color:#e4e4e7;border:1px solid #3f3f46;"
                            onmouseover="this.style.backgroundColor='#3f3f46'"
                            onmouseout="this.style.backgroundColor='#27272a'"
                            {{ wireNavigate() }}
                        >
                            Gestionar clientes →
                        </a>
                    </div>
                </div>

                <div
                    class="rounded px-3 py-2 text-xs"
                    style="background-color:rgba(139,92,246,0.08);color:#c4b5fd;border:1px solid rgba(139,92,246,0.25);"
                >
                    <strong>Nota:</strong> los cambios son efectivos inmediatamente — un cliente al que le quites
                    el acceso verá la lista de proyectos vacía en su próxima petición, sin necesidad de hacer logout.
                </div>
            @endif
    </div>
</div>
