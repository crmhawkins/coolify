@extends('layouts.base')
@section('body')
    @parent
    @if (isSubscribed() || !isCloud())
        <livewire:layout-popups />
    @endif
    <!-- Global search component - included once to prevent keyboard shortcut duplication -->
    <livewire:global-search />
    @auth
        <livewire:deployments-indicator />
        <div x-data="{
            open: false,
            init() {
                this.pageWidth = localStorage.getItem('pageWidth');
                if (!this.pageWidth) {
                    this.pageWidth = 'full';
                    localStorage.setItem('pageWidth', 'full');
                }
            }
        }" x-cloak class="mx-auto dark:text-inherit text-black"
            :class="pageWidth === 'full' ? '' : 'max-w-7xl'">
            <div class="relative z-50 lg:hidden" :class="open ? 'block' : 'hidden'" role="dialog" aria-modal="true">
                <div class="fixed inset-0 bg-black/80" x-on:click="open = false"></div>
                <div class="fixed inset-y-0 right-0 h-full flex">
                    <div class="relative flex flex-1 w-full max-w-56 min-w-0">
                        <div class="absolute top-0 flex justify-center w-16 pt-5 right-full">
                            <button type="button" class="-m-2.5 p-2.5" x-on:click="open = !open">
                                <span class="sr-only">Close sidebar</span>
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="flex flex-col pb-2 overflow-y-auto min-w-56 dark:bg-coolgray-100 gap-y-5 scrollbar min-w-0">
                            <x-navbar />
                        </div>
                    </div>
                </div>
            </div>

            <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-56 lg:flex-col min-w-0">
                <div class="flex flex-col overflow-y-auto grow gap-y-5 scrollbar min-w-0">
                    <x-navbar />
                </div>
            </div>

            <div
                class="sticky top-0 z-40 flex items-center justify-between px-4 py-4 gap-x-6 sm:px-6 lg:hidden bg-white/95 dark:bg-base/95 backdrop-blur-sm border-b border-neutral-300/50 dark:border-coolgray-200/50">
                <div class="flex items-center gap-3 flex-shrink-0">
                    <a href="/"
                        class="text-xl font-bold tracking-wide dark:text-white hover:opacity-80 transition-opacity">Coolify</a>
                    <livewire:switch-team />
                </div>
                <button type="button" class="-m-2.5 p-2.5 dark:text-warning" x-on:click="open = !open">
                    <span class="sr-only">Open sidebar</span>
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>

            <main class="lg:pl-56">
                <div class="p-4 sm:px-6 lg:px-8 lg:py-6">
                    <div class="relative">
                        {{-- Compression Tasks dropdown.

                             Hidden entirely from scoped client users
                             (they cannot compress, and the purple
                             button was bleed from the admin view into
                             the white-labelled client experience).

                             Admin view: polled Alpine component. The
                             button styling changes depending on the
                             worst task state (running → amber pulse,
                             failed → red, all done → gray ghost).
                             Poll cadence is 4s when the panel is open
                             and 20s while idle so the count updates in
                             the background without hammering the cache
                             endpoint. --}}
                        @if (! auth()->user()->isClient())
                        {{-- EVERY sizing attribute here is inline (width=,
                             height=, style="width:...") instead of Tailwind
                             classes. This is deliberate: the Coolify server
                             may not have rebuilt the Tailwind bundle after
                             the deploy, and any class Tailwind hasn't seen
                             before (w-3.5, w-[30rem], etc.) will simply
                             not exist in the CSS, leaving the SVG to
                             auto-scale to the full absolute container
                             width — that's the ~120px gigante icon you
                             saw in production. Inline width/height on
                             <svg> itself is the native spec default and
                             works regardless of CSS.

                             The <style> block defines the `spin` and
                             `pulse` keyframes under a scoped class name
                             (ct-*) so we don't clash with Tailwind's own
                             animations even if both exist. Everything
                             else is inline style or native attributes. --}}
                        <style>
                            @keyframes ct-spin {
                                to { transform: rotate(360deg); }
                            }
                            @keyframes ct-pulse {
                                0%, 100% { opacity: 1; }
                                50% { opacity: 0.55; }
                            }
                        </style>
                        <div
                            class="z-40"
                            style="position:absolute;right:0;top:0;"
                            x-data="compressionTasksPanel()"
                            x-init="init()"
                            @keydown.window.escape="open = false"
                        >
                            <div style="position:relative;" @click.outside="open = false">
                                <button
                                    type="button"
                                    @click="open = !open; if (open) { refresh(); }"
                                    :style="buttonStyle()"
                                    :title="buttonTitle()"
                                    class="rounded text-xs font-semibold shadow-sm transition-all focus:outline-none focus:ring-2 focus:ring-offset-1"
                                >
                                    {{-- Archive icon — sized with native
                                         SVG width/height AND inline style
                                         so it renders at 14×14 regardless
                                         of Tailwind state. --}}
                                    <svg width="14" height="14" style="width:14px;height:14px;flex-shrink:0;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8v13H3V8M1 3h22v5H1zM10 12h4" />
                                    </svg>
                                    <span x-text="buttonLabel()"></span>
                                    {{-- Running spinner — same inline sizing. --}}
                                    <svg x-show="runningCount > 0" x-cloak width="14" height="14" style="width:14px;height:14px;flex-shrink:0;display:block;animation:ct-spin 1s linear infinite;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                </button>

                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                                    class="rounded-lg border shadow-2xl"
                                    style="position:absolute;right:0;top:calc(100% + 0.5rem);width:30rem;max-width:calc(100vw - 2rem);max-height:32rem;overflow:auto;background-color:#18181b;border-color:#27272a;color:#e4e4e7;"
                                >
                                    {{-- Header --}}
                                    <div class="flex items-center justify-between gap-2 border-b px-4 py-3" style="border-color:#27272a;">
                                        <div class="min-w-0">
                                            <h4 class="text-sm font-semibold" style="color:#ffffff;">Tareas de compresión</h4>
                                            <p class="mt-0.5 text-[11px]" style="color:#71717a;">
                                                <span x-show="runningCount > 0" x-cloak><span x-text="runningCount"></span> en curso · </span>
                                                <span x-show="completedCount > 0" x-cloak><span x-text="completedCount"></span> completadas · </span>
                                                <span x-show="failedCount > 0" x-cloak><span x-text="failedCount"></span> fallidas · </span>
                                                actualización cada 4s
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button
                                                type="button"
                                                @click="refresh()"
                                                :disabled="loading"
                                                class="rounded px-2 py-1 text-[11px] font-semibold transition-colors disabled:opacity-50"
                                                style="background-color:#27272a;color:#c4b5fd;border:1px solid #3f3f46;"
                                                onmouseover="this.style.backgroundColor='#3f3f46'"
                                                onmouseout="this.style.backgroundColor='#27272a'"
                                                title="Actualizar ahora"
                                            >
                                                <svg width="12" height="12" :style="'width:12px;height:12px;display:inline-block;' + (loading ? 'animation:ct-spin 1s linear infinite;' : '')" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M5.07 19A9 9 0 0021 12.08M19 5a9 9 0 00-14 2" />
                                                </svg>
                                            </button>
                                            <button
                                                type="button"
                                                x-show="completedCount > 0 || failedCount > 0"
                                                x-cloak
                                                @click="clearDone()"
                                                :disabled="loading"
                                                class="rounded px-2 py-1 text-[11px] font-semibold transition-colors disabled:opacity-50"
                                                style="background-color:rgba(34,197,94,0.1);color:#86efac;border:1px solid rgba(34,197,94,0.3);"
                                                onmouseover="this.style.backgroundColor='rgba(34,197,94,0.2)'"
                                                onmouseout="this.style.backgroundColor='rgba(34,197,94,0.1)'"
                                                title="Eliminar tareas completadas y fallidas"
                                            >
                                                Limpiar listas
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Empty state --}}
                                    <template x-if="tasks.length === 0 && !loading">
                                        <div class="flex flex-col items-center justify-center px-4 py-10 text-center">
                                            <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:40px;height:40px;margin-bottom:0.5rem;color:#3f3f46;display:block;">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 8v13H3V8M1 3h22v5H1zM10 12h4" />
                                            </svg>
                                            <p class="text-sm" style="color:#a1a1aa;">No hay tareas de compresión</p>
                                            <p class="mt-0.5 text-[11px]" style="color:#52525b;">Las tareas aparecen aquí cuando comprimes ficheros desde el explorador</p>
                                        </div>
                                    </template>

                                    {{-- Loading placeholder on first open --}}
                                    <template x-if="tasks.length === 0 && loading">
                                        <div class="px-4 py-6 text-center">
                                            <p class="text-xs" style="color:#71717a;">Cargando tareas…</p>
                                        </div>
                                    </template>

                                    {{-- Task list. Inline styles only so nothing
                                         depends on Tailwind utility classes that
                                         may be missing from the compiled bundle. --}}
                                    <template x-if="tasks.length > 0">
                                        <ul style="list-style:none;margin:0;padding:0;">
                                            <template x-for="task in tasks" :key="task.id || task.archive_path">
                                                <li :style="taskRowStyle(task)">
                                                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;">
                                                        <div style="min-width:0;flex:1 1 0%;">
                                                            <div style="display:flex;align-items:center;gap:0.5rem;">
                                                                <span style="flex-shrink:0;display:inline-flex;" x-html="taskIcon(task)"></span>
                                                                <p style="margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.875rem;font-weight:600;color:#ffffff;" x-text="task.archive_name || 'archive.zip'"></p>
                                                            </div>
                                                            <p style="margin:0.25rem 0 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.6875rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#71717a;" x-text="'📁 ' + (task.directory || '/')"></p>
                                                            <p x-show="task.last_message" x-cloak style="margin:0.25rem 0 0 0;word-break:break-word;font-size:0.6875rem;" :style="'margin:0.25rem 0 0 0;word-break:break-word;font-size:0.6875rem;color:' + (task.status === 'failed' ? '#f87171' : '#a1a1aa') + ';'" x-text="task.last_message"></p>
                                                            <p x-show="task.created_at" x-cloak style="margin:0.25rem 0 0 0;font-size:0.625rem;color:#52525b;" x-text="relativeTime(task.created_at)"></p>
                                                        </div>
                                                        <span :style="statusPillStyle(task)" x-text="(task.status || 'running').toUpperCase()"></span>
                                                    </div>
                                                    <div style="margin-top:0.5rem;display:flex;align-items:center;gap:0.5rem;" x-show="task.open_url" x-cloak>
                                                        <a
                                                            :href="task.open_url"
                                                            style="display:inline-block;border-radius:0.25rem;padding:0.25rem 0.5rem;font-size:0.6875rem;font-weight:600;background-color:#8b5cf6;color:#ffffff;text-decoration:none;"
                                                            onmouseover="this.style.backgroundColor='#7c3aed'"
                                                            onmouseout="this.style.backgroundColor='#8b5cf6'"
                                                        >
                                                            Abrir ubicación
                                                        </a>
                                                    </div>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- Alpine component factory. Defined once as a
                             window-level function so the x-data block
                             stays readable. Uses fetch() against the
                             existing compression.tasks GET endpoint
                             and a new compression.tasks.clear POST
                             endpoint for the "Limpiar listas" button. --}}
                        <script>
                            window.compressionTasksPanel = window.compressionTasksPanel || function () {
                                return {
                                    open: false,
                                    tasks: [],
                                    loading: false,
                                    pollHandle: null,
                                    init() {
                                        // Initial fetch on page ready, then poll at the
                                        // background cadence until the user opens the
                                        // dropdown (at which point refresh() bumps to
                                        // the foreground cadence).
                                        this.refresh();
                                        this.schedulePoll();
                                    },
                                    schedulePoll() {
                                        if (this.pollHandle) {
                                            clearInterval(this.pollHandle);
                                        }
                                        const interval = this.open ? 4000 : 20000;
                                        this.pollHandle = setInterval(() => this.refresh(true), interval);
                                    },
                                    async refresh(silent = false) {
                                        if (!silent) {
                                            this.loading = true;
                                        }
                                        try {
                                            const res = await fetch('{{ route('compression.tasks') }}', {
                                                method: 'GET',
                                                headers: { 'Accept': 'application/json' },
                                                credentials: 'same-origin',
                                            });
                                            if (!res.ok) return;
                                            const payload = await res.json();
                                            this.tasks = Array.isArray(payload.tasks) ? payload.tasks : [];
                                            // Re-schedule the poll in case the open state
                                            // changed between ticks.
                                            this.schedulePoll();
                                        } catch (e) {
                                            // Silently ignore — we'll try again on the next tick.
                                        } finally {
                                            this.loading = false;
                                        }
                                    },
                                    async clearDone() {
                                        if (!confirm('¿Eliminar de la lista las tareas completadas y fallidas? Las tareas en curso no se tocan.')) return;
                                        this.loading = true;
                                        try {
                                            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                                            const res = await fetch('{{ route('compression.tasks.clear') }}', {
                                                method: 'POST',
                                                headers: {
                                                    'Accept': 'application/json',
                                                    'Content-Type': 'application/json',
                                                    'X-CSRF-TOKEN': token,
                                                },
                                                credentials: 'same-origin',
                                                body: JSON.stringify({ scope: 'done' }),
                                            });
                                            if (!res.ok) return;
                                            const payload = await res.json();
                                            this.tasks = Array.isArray(payload.tasks) ? payload.tasks : [];
                                        } catch (e) {
                                        } finally {
                                            this.loading = false;
                                        }
                                    },
                                    get runningCount() {
                                        return this.tasks.filter(t => (t?.status || 'running') === 'running').length;
                                    },
                                    get completedCount() {
                                        return this.tasks.filter(t => t?.status === 'completed').length;
                                    },
                                    get failedCount() {
                                        return this.tasks.filter(t => t?.status === 'failed').length;
                                    },
                                    // Visible label on the trigger button. Always
                                    // readable at a glance: "N ⇣" when tasks are
                                    // running, "Compression Tasks" when empty.
                                    buttonLabel() {
                                        if (this.tasks.length === 0) return 'Compresiones';
                                        const running = this.runningCount;
                                        if (running > 0) return `${running} en curso · ${this.tasks.length} totales`;
                                        return `${this.tasks.length} ${this.tasks.length === 1 ? 'tarea' : 'tareas'}`;
                                    },
                                    buttonTitle() {
                                        if (this.runningCount > 0) return `Hay ${this.runningCount} compresión(es) en curso. Haz clic para ver detalles.`;
                                        if (this.failedCount > 0) return `Hay ${this.failedCount} compresión(es) fallidas. Haz clic para revisar.`;
                                        if (this.completedCount > 0) return `${this.completedCount} completadas. Haz clic para gestionarlas.`;
                                        return 'Tareas de compresión en segundo plano';
                                    },
                                    // Inline style for the trigger button. We
                                    // deliberately avoid Tailwind utility
                                    // classes here because arbitrary/custom
                                    // color classes may be missing from the
                                    // compiled bundle on the server (that's
                                    // what blew up the button size and
                                    // styling in production). Everything the
                                    // button needs — colors, layout, padding,
                                    // font, animation — is hard-coded in
                                    // this style string so nothing depends
                                    // on Tailwind state.
                                    buttonStyle() {
                                        const common = 'display:inline-flex;align-items:center;gap:0.5rem;padding:0.375rem 0.75rem;line-height:1;font-size:0.75rem;font-weight:600;border-radius:0.375rem;border:1px solid transparent;cursor:pointer;';
                                        if (this.tasks.length === 0) {
                                            // Ghost gray so it doesn't bleed into every page.
                                            return common + 'background-color:#27272a;color:#71717a;border-color:#3f3f46;opacity:0.65;';
                                        }
                                        if (this.failedCount > 0) {
                                            return common + 'background-color:#dc2626;color:#ffffff;border-color:#b91c1c;';
                                        }
                                        if (this.runningCount > 0) {
                                            return common + 'background-color:#f59e0b;color:#ffffff;border-color:#d97706;animation:ct-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;';
                                        }
                                        return common + 'background-color:#16a34a;color:#ffffff;border-color:#15803d;';
                                    },
                                    taskRowStyle(task) {
                                        const common = 'padding:0.75rem 1rem;border-bottom:1px solid #27272a;';
                                        const s = task?.status || 'running';
                                        if (s === 'failed') return common + 'border-left:3px solid #ef4444;';
                                        if (s === 'completed') return common + 'border-left:3px solid #22c55e;';
                                        return common + 'border-left:3px solid #f59e0b;';
                                    },
                                    statusPillStyle(task) {
                                        const common = 'flex-shrink:0;display:inline-block;border-radius:0.25rem;padding:0.125rem 0.5rem;font-size:0.625rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;text-transform:uppercase;border-width:1px;border-style:solid;';
                                        const s = task?.status || 'running';
                                        if (s === 'failed') return common + 'background-color:rgba(239,68,68,0.15);color:#f87171;border-color:rgba(239,68,68,0.3);';
                                        if (s === 'completed') return common + 'background-color:rgba(34,197,94,0.15);color:#4ade80;border-color:rgba(34,197,94,0.3);';
                                        return common + 'background-color:rgba(245,158,11,0.15);color:#fbbf24;border-color:rgba(245,158,11,0.3);';
                                    },
                                    taskIcon(task) {
                                        // Inline width/height + style because the
                                        // Tailwind classes w-3.5 / h-3.5 may not
                                        // exist in the compiled bundle on the
                                        // server. Without a size, SVGs balloon to
                                        // the container width (the gigante icon
                                        // bug from production). Colors come from
                                        // `color:` inline style so they also
                                        // survive an uncompiled Tailwind.
                                        const s = task?.status || 'running';
                                        const sz = 'width="14" height="14" style="width:14px;height:14px;display:inline-block;flex-shrink:0';
                                        if (s === 'failed') {
                                            return `<svg ${sz};color:#f87171;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>`;
                                        }
                                        if (s === 'completed') {
                                            return `<svg ${sz};color:#4ade80;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>`;
                                        }
                                        return `<svg ${sz};color:#fbbf24;animation:ct-spin 1s linear infinite;" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>`;
                                    },
                                    // Human-readable relative time. Accepts ISO
                                    // strings or "YYYY-MM-DD HH:MM:SS" formats —
                                    // FileExplorer writes the latter via
                                    // now()->toDateTimeString().
                                    relativeTime(value) {
                                        if (!value) return '';
                                        const d = new Date(String(value).replace(' ', 'T'));
                                        if (isNaN(d.getTime())) return '';
                                        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
                                        if (diff < 5) return 'ahora mismo';
                                        if (diff < 60) return `hace ${diff}s`;
                                        if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
                                        if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
                                        return `hace ${Math.floor(diff / 86400)} d`;
                                    },
                                };
                            };
                        </script>
                        @endif
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>
    @endauth
@endsection
{{-- resync-marker 2026-04-08 --}}
