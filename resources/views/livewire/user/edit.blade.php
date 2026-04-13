<div>
    <x-slot:title>
        Editar usuario | Coolify
    </x-slot>

    <div class="pb-6">
        <h1>Editar usuario</h1>
        <div class="subtitle">{{ $targetUser->email }}</div>
    </div>

    @if ($regeneratedPassword)
        <div class="p-4 mb-6 border border-coolgray-300 rounded">
            <h2 class="pb-2">Contraseña regenerada</h2>
            <p class="pb-2">Nueva contraseña (no se volverá a mostrar):</p>
            <p><code>{{ $regeneratedPassword }}</code></p>
            @if ($emailSent)
                <p class="pt-2 text-sm dark:text-success">Las credenciales también se enviaron por email.</p>
            @elseif ($emailError)
                <p class="pt-2 text-sm dark:text-warning">{{ $emailError }}</p>
            @endif
        </div>
    @endif

    <form class="flex flex-col" wire:submit="submit">
        <div class="flex flex-col gap-2 max-w-xl">
            <x-forms.input id="name" label="Nombre" required />
            <x-forms.input id="email" type="email" label="Email" required />
            <x-forms.checkbox id="isClient" label="Marcar como cliente (acceso restringido a proyectos asignados)" />
        </div>

        <h2 class="pt-6">Proyectos accesibles</h2>
        <div class="subtitle">Selecciona los proyectos a los que este usuario tiene acceso.</div>

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
            <x-forms.button type="submit">Guardar cambios</x-forms.button>
        </div>
    </form>

    <div class="pt-8">
        <h2>Acciones</h2>
        <div class="flex gap-2 pt-4">
            <x-forms.button wire:click="regeneratePassword" wire:confirm="¿Seguro que quieres regenerar la contraseña? Las sesiones activas del usuario se cerrarán.">
                Regenerar contraseña
            </x-forms.button>
        </div>
    </div>

    @can('delete', $targetUser)
        <div class="pt-8">
            <h2>Zona de peligro</h2>
            <div class="pb-4">Eliminar al usuario es irreversible. Los proyectos NO se borran, solo se desasocia.</div>
            <x-modal-confirmation
                title="¿Eliminar usuario?"
                buttonTitle="Eliminar usuario"
                isErrorButton
                submitAction="delete"
                :actions="['El usuario será eliminado permanentemente de Coolify.', 'Sus proyectos asignados permanecerán intactos.']"
                confirmationText="{{ $targetUser->email }}"
                confirmationLabel="Confirma escribiendo el email del usuario"
                shortConfirmationLabel="Email del usuario"
                :confirmWithPassword="false"
                step2ButtonText="Eliminar permanentemente" />
        </div>
    @endcan
</div>
{{-- resync-marker 2026-04-08 --}}
