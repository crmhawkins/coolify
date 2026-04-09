<?php

namespace App\Livewire\Terminal;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Livewire\Attributes\On;
use Livewire\Component;

class Index extends Component
{
    public $selected_uuid = 'default';

    public $servers = [];

    public $containers = [];

    public bool $isLoadingContainers = true;

    public function mount()
    {
        $this->servers = Server::isReachable()->get()->filter(function ($server) {
            return $server->isTerminalEnabled();
        });
    }

    public function loadContainers()
    {
        try {
            $this->containers = $this->getAllActiveContainers();
        } catch (\Exception $e) {
            return handleError($e, $this);
        } finally {
            $this->isLoadingContainers = false;
        }
    }

    private function getAllActiveContainers()
    {
        // Build a lookup of UUID → project + resource name so we can
        // turn raw container names like `laravel-b14bczrotovsbdz2vzmixjj1`
        // into human-readable labels like `Apartamentos → laravel`.
        // Done once up-front so the per-container loop below is an
        // O(1) hash lookup instead of an extra query per container.
        $resourceLookup = $this->buildResourceLookupByUuid();

        return collect($this->servers)->flatMap(function ($server) use ($resourceLookup) {
            if (! $server->isFunctional()) {
                return [];
            }

            return $server->loadAllContainers()->map(function ($container) use ($server, $resourceLookup) {
                $state = data_get_str($container, 'State')->lower();
                if ($state->contains('running')) {
                    $name = (string) data_get($container, 'Names');

                    return [
                        'name' => $name,
                        'display_name' => $this->buildContainerDisplayName($name, $resourceLookup),
                        'connection_name' => $name,
                        'uuid' => $name,
                        'status' => data_get_str($container, 'State')->lower(),
                        'server' => $server,
                        'server_uuid' => $server->uuid,
                    ];
                }

                return null;
            })->filter();
        })->sortBy('display_name');
    }

    /**
     * Builds a UUID → {project, resource} lookup for every Service and
     * Application owned by the current team. Container names in Coolify
     * follow the convention `{role}-{resource.uuid}` (see
     * ServiceApplication::getContainerName(), SetupWordPress, etc.), so
     * the suffix after the last dash is the key into this lookup.
     *
     * @return array<string, array{project: string, resource: string}>
     */
    private function buildResourceLookupByUuid(): array
    {
        $lookup = [];

        try {
            foreach (Service::ownedByCurrentTeamCached() as $service) {
                $uuid = (string) $service->uuid;
                if ($uuid === '') {
                    continue;
                }
                $lookup[$uuid] = [
                    'project' => (string) (data_get($service, 'environment.project.name') ?? ''),
                    'resource' => (string) ($service->name ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // Swallow: an empty lookup just means we fall back to raw
            // container names in the selector, which is the old UX.
        }

        try {
            foreach (Application::ownedByCurrentTeam()->get() as $application) {
                $uuid = (string) $application->uuid;
                if ($uuid === '') {
                    continue;
                }
                $lookup[$uuid] = [
                    'project' => (string) (data_get($application, 'environment.project.name') ?? ''),
                    'resource' => (string) ($application->name ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // Same rationale as above.
        }

        return $lookup;
    }

    /**
     * Turns a raw container name into a human-readable label of the
     * form "ProjectName → role". The role is the container name prefix
     * (everything before the last dash) and the project comes from the
     * resource lookup keyed by the UUID suffix. If the suffix does not
     * match any known Service or Application we fall back to the raw
     * container name so Coolify's own system containers (coolify-db,
     * coolify-proxy, …) still render something meaningful.
     *
     * @param  array<string, array{project: string, resource: string}>  $resourceLookup
     */
    private function buildContainerDisplayName(string $containerName, array $resourceLookup): string
    {
        if ($containerName === '') {
            return $containerName;
        }

        $lastDash = strrpos($containerName, '-');
        if ($lastDash === false) {
            return $containerName;
        }

        $role = substr($containerName, 0, $lastDash);
        $suffix = substr($containerName, $lastDash + 1);

        if ($role === '' || $suffix === '') {
            return $containerName;
        }

        if (! isset($resourceLookup[$suffix])) {
            return $containerName;
        }

        $entry = $resourceLookup[$suffix];
        $projectName = $entry['project'] !== '' ? $entry['project'] : $entry['resource'];

        if ($projectName === '') {
            return $containerName;
        }

        return $projectName.' → '.$role;
    }

    public function updatedSelectedUuid()
    {
        if ($this->selected_uuid === 'default') {
            // When cleared to default, do nothing (no error message)
            return;
        }
        $this->connectToContainer();
    }

    #[On('connectToContainer')]
    public function connectToContainer()
    {
        if ($this->selected_uuid === 'default') {
            $this->dispatch('error', 'Please select a server or a container.');

            return;
        }
        $container = collect($this->containers)->firstWhere('uuid', $this->selected_uuid);
        $this->dispatch('send-terminal-command',
            isset($container),
            $container['connection_name'] ?? $this->selected_uuid,
            $container['server_uuid'] ?? $this->selected_uuid
        );
    }

    public function render()
    {
        return view('livewire.terminal.index');
    }
}
// resync-marker 2026-04-08
