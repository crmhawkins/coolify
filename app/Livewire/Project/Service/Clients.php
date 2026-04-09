<?php

namespace App\Livewire\Project\Service;

use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Per-service "Clientes" tab. Lets team admins quickly toggle which
 * scoped client users have access to the project this service belongs
 * to, without having to navigate to Users → Edit → Assigned projects
 * every time. The assignment is inherently per-project (the pivot is
 * project_user), so toggling here also affects every other service
 * inside the same project — the view makes this explicit.
 *
 * This component is ONLY reachable by non-client users: the route is
 * gated by the `restrict.client` middleware, the nav link in
 * heading.blade.php is wrapped in a gate, and the mount() method
 * still 403s as belt-and-braces in case the middleware ever slips.
 */
class Clients extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public Project $project;

    public array $parameters;

    /**
     * Every scoped client user in the current team, ordered by name.
     * We keep the full collection in state so the view can render the
     * checkbox list without a second query on every Livewire round-trip.
     *
     * @var Collection<int, User>
     */
    public Collection $availableClients;

    /**
     * IDs of the clients currently selected in the form. Bound to the
     * checkbox group via wire:model; save() syncs the pivot against
     * this array so unchecking a row revokes access.
     *
     * @var array<int, int>
     */
    public array $assignedClientIds = [];

    /**
     * Snapshot of assignedClientIds at load time. Used to compute a
     * "cambios sin guardar" badge in the view without needing an
     * Alpine wrapper — the dirty state is derivable from PHP state.
     *
     * @var array<int, int>
     */
    public array $initialAssignedClientIds = [];

    public function mount(): void
    {
        // Route-level restrict.client middleware should have already
        // rejected client users, but we add an explicit 403 here so
        // misconfigured routing cannot silently expose the page.
        if (auth()->user()?->isClient()) {
            abort(403, 'Los clientes no tienen acceso a la gestión de permisos.');
        }

        $this->parameters = get_route_parameters();

        // Team scoping: funnels the lookup through ownedByCurrentTeam()
        // so a UUID from another team 404s before the policy layer is
        // consulted. Same pattern as the other RootKit components
        // (LaravelManager, LaravelArtisan, …).
        $this->service = Service::ownedByCurrentTeam()
            ->whereUuid(request()->route('service_uuid'))
            ->firstOrFail();

        $this->authorize('view', $this->service);

        // The pivot is project_user, not service_user, so access is
        // always granted at the project level. Pull the project from
        // the service's environment to centralise the lookup.
        $project = $this->service->environment?->project;
        if (! $project) {
            abort(404, 'No se encontró el proyecto asociado al servicio.');
        }
        $this->project = $project;

        $this->loadClients();
    }

    /**
     * Loads every client in the current team and captures the initial
     * assignment snapshot so save() can diff against it. Called on
     * mount and whenever the underlying data needs to refresh (e.g.
     * after save() runs sync() and we want the badge to reset).
     */
    private function loadClients(): void
    {
        $teamId = currentTeam()->id;

        $this->availableClients = User::query()
            ->where('is_client', true)
            ->whereHas('teams', fn ($q) => $q->where('teams.id', $teamId))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        // withoutGlobalScopes() because the RestrictsToClientProjects
        // scope would filter this project out for a client user — but
        // we only reach this code as a non-client, so it's a no-op.
        // Explicit for clarity.
        $this->assignedClientIds = $this->project
            ->assignedUsers()
            ->whereIn('users.id', $this->availableClients->pluck('id'))
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        sort($this->assignedClientIds);
        $this->initialAssignedClientIds = $this->assignedClientIds;
    }

    public function save(): void
    {
        $this->authorize('update', $this->service);

        // Coerce + guard the form input: only accept integers that
        // correspond to client users in the current team. This kills
        // any attempt to assign arbitrary user IDs (e.g. an admin of
        // another team) by tampering with the Livewire payload.
        $legalIds = $this->availableClients->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedIds = array_values(array_intersect(
            array_map('intval', $this->assignedClientIds),
            $legalIds
        ));

        try {
            $this->project->assignedUsers()->syncWithoutDetaching($selectedIds);

            // Detach any client in the team that is NOT in the new
            // selection. Using detach() explicitly (rather than plain
            // sync) keeps non-client assignments — if there are any —
            // untouched, because syncWithoutDetaching + targeted detach
            // only touches the client rows we care about.
            $toRevoke = array_diff($legalIds, $selectedIds);
            if ($toRevoke !== []) {
                $this->project->assignedUsers()->detach($toRevoke);
            }

            // Flush the per-request cache inside RestrictsToClientProjects
            // so a client user whose assignment we just changed will see
            // the new state on their next request without a refresh.
            \App\Models\Project::flushClientProjectIdsCache();

            $this->loadClients();
            $this->dispatch('success', 'Acceso de clientes actualizado.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Exposed to the view as a computed property: returns true when
     * the user has toggled at least one checkbox since the last load.
     */
    public function getIsDirtyProperty(): bool
    {
        $current = array_map('intval', $this->assignedClientIds);
        sort($current);

        return $current !== $this->initialAssignedClientIds;
    }

    public function render()
    {
        return view('livewire.project.service.clients');
    }
}
