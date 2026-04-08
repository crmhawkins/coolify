<?php

namespace App\Livewire;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class Dashboard extends Component
{
    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public function mount()
    {
        // Scoped client users only see their assigned projects on the
        // Dashboard. They do not see Servers or Private Keys at all — those
        // sections are hidden in the view (dashboard.blade.php). The project
        // list is filtered automatically by the RestrictsToClientProjects
        // global scope on the Project model, so we can use the same query
        // as admins and let the scope do its job.
        if (auth()->check() && auth()->user()->isClient()) {
            $this->privateKeys = collect();
            $this->servers = collect();
            $this->projects = Project::query()
                ->with('environments')
                ->orderByRaw('LOWER(name)')
                ->get();

            return;
        }

        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::ownedByCurrentTeam()->with('environments')->get();
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
// resync-marker 2026-04-08
