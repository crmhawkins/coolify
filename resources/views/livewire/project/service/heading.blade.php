<div wire:poll.10000ms="checkStatus" class="pb-6">
    <livewire:project.shared.configuration-checker :resource="$service" />
    <x-slide-over @startservice.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>Service Startup</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" fullHeight />
        </x-slot:content>
    </x-slide-over>
    <h1>{{ $title }}</h1>
    <x-resources.breadcrumbs :resource="$service" :parameters="$parameters" />
    <div class="navbar-main" x-data">
        <nav class="flex shrink-0 gap-4 items-center whitespace-nowrap scrollbar min-h-10">
            <a class="{{ request()->routeIs('project.service.configuration') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.service.configuration', $parameters) }}">
                <button>Configuration</button>
            </a>
            <a class="{{ request()->routeIs('project.service.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('project.service.logs', $parameters) }}">
                <button>Logs</button>
            </a>
            @can('canAccessTerminal')
                <a class="{{ request()->routeIs('project.service.command') ? 'dark:text-white' : '' }}"
                    href="{{ route('project.service.command', $parameters) }}">
                    <button>Terminal</button>
                </a>
                <a class="{{ request()->routeIs('project.service.files') ? 'dark:text-white' : '' }}"
                    href="{{ route('project.service.files', $parameters) }}">
                    <button>Files</button>
                </a>
            @endcan
            <x-services.links :service="$service" />
        </nav>
        @if ($service->isDeployable)
            <div class="flex flex-wrap order-first gap-2 items-center sm:order-last">
                <x-services.advanced :service="$service" />
                @if (str($service->status)->contains('running'))
                    {{-- Redeploy: full stop + start pipeline. Picks up
                         .env / php.ini / compose changes. Previously
                         mislabelled as "Restart". --}}
                    <x-forms.button title="Redeploy (stop + start pipeline)"
                        @click="$wire.dispatch('redeployEvent')">
                        <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path d="M19.933 13.041 a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                <path d="M20 4v5h-5" />
                            </g>
                        </svg>
                        Redeploy
                    </x-forms.button>
                    {{-- Restart: plain `docker restart` on each
                         container, no rebuild. Use when something has
                         gone unhealthy and you just want to kick it. --}}
                    <x-forms.button title="Restart (docker restart, sin rebuild)"
                        @click="$wire.dispatch('restartEvent')">
                        <svg class="w-5 h-5 dark:text-success" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                            fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M12 2v4" />
                            <path d="m16.2 7.8 2.9-2.9" />
                            <path d="M18 12h4" />
                            <path d="m16.2 16.2 2.9 2.9" />
                            <path d="M12 18v4" />
                            <path d="m4.9 19.1 2.9-2.9" />
                            <path d="M2 12h4" />
                            <path d="m4.9 4.9 2.9 2.9" />
                        </svg>
                        Restart
                    </x-forms.button>
                    <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop" :dispatchEvent="true"
                        submitAction="stop" dispatchEventType="stopEvent" :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]"
                        :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Continue" step2ButtonText="Confirm">
                        <x-slot:button-title>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                                <path
                                    d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                            </svg>
                            Stop
                        </x-slot:button-title>
                    </x-modal-confirmation>
                @elseif (str($service->status)->contains('degraded'))
                    {{-- Same Redeploy + Restart pair as the running
                         branch above; degraded just means one container
                         is unhappy, the actions are the same. --}}
                    <x-forms.button title="Redeploy (stop + start pipeline)"
                        @click="$wire.dispatch('redeployEvent')">
                        <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                <path d="M20 4v5h-5" />
                            </g>
                        </svg>
                        Redeploy
                    </x-forms.button>
                    <x-forms.button title="Restart (docker restart, sin rebuild)"
                        @click="$wire.dispatch('restartEvent')">
                        <svg class="w-5 h-5 dark:text-success" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                            fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M12 2v4" />
                            <path d="m16.2 7.8 2.9-2.9" />
                            <path d="M18 12h4" />
                            <path d="m16.2 16.2 2.9 2.9" />
                            <path d="M12 18v4" />
                            <path d="m4.9 19.1 2.9-2.9" />
                            <path d="M2 12h4" />
                            <path d="m4.9 4.9 2.9 2.9" />
                        </svg>
                        Restart
                    </x-forms.button>
                    <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop" :dispatchEvent="true"
                        submitAction="stop" dispatchEventType="stopEvent" :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]"
                        :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Continue" step2ButtonText="Confirm">
                        <x-slot:button-title>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                                <path
                                    d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                            </svg>
                            Stop
                        </x-slot:button-title>
                    </x-modal-confirmation>
                @elseif (str($service->status)->contains('exited'))
                    <button @click="$wire.dispatch('startEvent')" class="gap-2 button">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M7 4v16l13 -8z" />
                        </svg>
                        Deploy
                    </button>
                @else
                    <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop" :dispatchEvent="true"
                        submitAction="stop" dispatchEventType="stopEvent" :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]"
                        :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Continue" step2ButtonText="Confirm">
                        <x-slot:button-title>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                                <path
                                    d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                            </svg>
                            Stop
                        </x-slot:button-title>
                    </x-modal-confirmation>
                    <button @click="$wire.dispatch('startEvent')" class="gap-2 button">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M7 4v16l13 -8z" />
                        </svg>
                        Deploy
                    </button>
                @endif
            </div>
        @else
            <div class="flex flex-wrap order-first gap-2 items-center sm:order-last">
                <div class="text-error">
                    Unable to deploy. <a class="underline font-bold cursor-pointer" {{ wireNavigate() }}
                        href="{{ route('project.service.environment-variables', $parameters) }}">
                        Required environment variables missing.</a>
                </div>
            </div>
        @endif
    </div>
    @script
        <script>
            $wire.$on('stopEvent', () => {
                $wire.$dispatch('info',
                    'Gracefully stopping service.<br/><br/>It could take a while depending on the service.');
                $wire.$call('stop');
            });
            $wire.$on('startEvent', async () => {
                const isDeploymentProgress = await $wire.$call('checkDeployments');
                if (isDeploymentProgress) {
                    $wire.$dispatch('error',
                        'There is a deployment in progress.<br><br>You can force deploy in the "Advanced" section.'
                    );
                    return;
                }
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('start');
            });
            $wire.$on('forceDeployEvent', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('forceDeploy');
            });
            // Redeploy: the old "restart" flow — stop + start pipeline
            // with progress slide-over. This is what the Redeploy button
            // in the header dispatches now.
            $wire.$on('redeployEvent', async () => {
                const isDeploymentProgress = await $wire.$call('checkDeployments');
                if (isDeploymentProgress) {
                    $wire.$dispatch('error',
                        'There is a deployment in progress.<br><br>You can force deploy in the "Advanced" section.'
                    );
                    return;
                }
                $wire.$dispatch('info',
                    'Redesplegando servicio.<br/><br/>Puede tardar un poco.');
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('redeploy');
            });

            // Restart: plain `docker restart` per container, no rebuild,
            // no progress slide-over (it is fast enough to resolve in
            // the success/error toast alone).
            $wire.$on('restartEvent', () => {
                $wire.$dispatch('info', 'Reiniciando contenedores…');
                $wire.$call('restart');
            });
            $wire.$on('pullAndRestartEvent', () => {
                $wire.$dispatch('info', 'Pulling new images and restarting service.');
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('pullAndRestartEvent');
            });
            $wire.on('imagePulled', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$dispatch('info', 'Restarting service.');
            });
        </script>
    @endscript
</div>
{{-- resync-marker 2026-04-08 --}}
