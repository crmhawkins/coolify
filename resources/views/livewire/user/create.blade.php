<div>
    <x-slot:title>
        Nuevo usuario | Coolify
    </x-slot>

    <div class="pb-6">
        <h1>Nuevo usuario</h1>
        <div class="subtitle">Crea un usuario y, opcionalmente, asígnale acceso a proyectos.</div>
    </div>

    @if ($generatedPassword)
        <div class="p-4 mb-6 border border-coolgray-300 rounded">
            <h2 class="pb-2">Usuario creado</h2>
            <p class="pb-2">Comparte estas credenciales con el usuario. Esta contraseña no se volverá a mostrar.</p>
            <ul class="space-y-1 text-sm">
                <li><strong>URL:</strong> <code>{{ $loginUrl }}</code></li>
                <li><strong>Email:</strong> <code>{{ $createdEmail }}</code></li>
                <li><strong>Contraseña:</strong> <code>{{ $generatedPassword }}</code></li>
            </ul>
            @if ($emailSent)
                <p class="pt-2 text-sm dark:text-success">Las credenciales también se enviaron por email.</p>
            @elseif ($emailError)
                <p class="pt-2 text-sm dark:text-warning">{{ $emailError }}</p>
            @endif
            <div class="pt-4 flex gap-2">
                <a class="button" href="{{ route('users.index') }}" {{ wireNavigate() }}>Volver al listado</a>
            </div>
        </div>
    @endif

    <form class="flex flex-col" wire:submit="submit">
        <div class="flex flex-col gap-2 max-w-xl">
            <x-forms.input id="name" label="Nombre" required />
            <x-forms.input id="email" type="email" label="Email" required />
            <x-forms.checkbox id="isClient" label="Marcar como cliente (acceso restringido a proyectos asignados)" />
        </div>

        <h2 class="pt-6">Proyectos accesibles</h2>
        <div class="subtitle">Selecciona los proyectos a los que el usuario tendrá acceso. Si no es cliente, ignora esta lista.</div>

        @if ($availableProjects->isEmpty())
            <div class="text-sm text-neutral-500">No tienes proyectos en este team todavía.</div>
        @else
            <div x-data="{ search: '' }" class="pt-2">
                <input type="text" x-model="search" placeholder="Buscar proyecto..."
                    class="w-full sm:w-80 mb-3 px-3 py-2 text-sm border border-coolgray-300 rounded-md bg-transparent focus:border-warning focus:outline-none">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-x-6 gap-y-1 max-h-[28rem] overflow-y-auto border border-coolgray-300 rounded-md p-4">
                    @foreach ($availableProjects->sortBy('name') as $project)
                        <label x-show="search === '' || '{{ strtolower($project->name) }}'.includes(search.toLowerCase())"
                            class="flex items-center gap-2 py-0.5 cursor-pointer hover:bg-coolgray-200 rounded px-1">
                            <input type="checkbox" wire:model="assignedProjectIds" value="{{ $project->id }}" class="rounded">
                            <span class="truncate text-sm">{{ $project->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="pt-6">
            <x-forms.button type="submit">Crear usuario</x-forms.button>
        </div>
    </form>
</div>
{{-- resync-marker 2026-04-08 --}}
