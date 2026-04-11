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
                            style="position:absolute;right:4.5rem;top:0;"
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
                                    {{-- Header — renamed from "Tareas de
                                         compresión" to "Tareas en segundo
                                         plano" because this panel now
                                         tracks both compressions AND
                                         extractions (background-extraction
                                         work, April 2026). --}}
                                    <div class="flex items-center justify-between gap-2 border-b px-4 py-3" style="border-color:#27272a;">
                                        <div class="min-w-0">
                                            <h4 class="text-sm font-semibold" style="color:#ffffff;">Tareas en segundo plano</h4>
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
                                            <p class="text-sm" style="color:#a1a1aa;">No hay tareas en segundo plano</p>
                                            <p class="mt-0.5 text-[11px]" style="color:#52525b;">Las tareas aparecen aquí cuando comprimes o extraes ficheros desde el explorador</p>
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
                                                                {{-- Kind pill: "COMPRIMIR" / "EXTRAER"
                                                                     so users can tell a compression task
                                                                     apart from an extraction one at a
                                                                     glance. Same row already had a
                                                                     status pill (RUNNING/COMPLETED/
                                                                     FAILED) on the right — this one is
                                                                     an identity tag. --}}
                                                                <span :style="taskKindPillStyle(task)" x-text="taskKindLabel(task)"></span>
                                                                <p style="margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.875rem;font-weight:600;color:#ffffff;" x-text="task.archive_name || 'archive.zip'"></p>
                                                            </div>
                                                            <p style="margin:0.25rem 0 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.6875rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#71717a;" x-text="'📁 ' + (task.directory || '/')"></p>
                                                            <p x-show="task.last_message" x-cloak style="margin:0.25rem 0 0 0;word-break:break-word;font-size:0.6875rem;" :style="'margin:0.25rem 0 0 0;word-break:break-word;font-size:0.6875rem;color:' + (task.status === 'failed' ? '#f87171' : '#a1a1aa') + ';'" x-text="task.last_message"></p>
                                                            <p x-show="task.created_at" x-cloak style="margin:0.25rem 0 0 0;font-size:0.625rem;color:#52525b;" x-text="taskTimeLabel(task)"></p>
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
                                        if (this.tasks.length === 0) return 'Tareas';
                                        const running = this.runningCount;
                                        if (running > 0) return `${running} en curso · ${this.tasks.length} totales`;
                                        return `${this.tasks.length} ${this.tasks.length === 1 ? 'tarea' : 'tareas'}`;
                                    },
                                    buttonTitle() {
                                        if (this.runningCount > 0) return `Hay ${this.runningCount} tarea(s) en curso. Haz clic para ver detalles.`;
                                        if (this.failedCount > 0) return `Hay ${this.failedCount} tarea(s) fallidas. Haz clic para revisar.`;
                                        if (this.completedCount > 0) return `${this.completedCount} completadas. Haz clic para gestionarlas.`;
                                        return 'Tareas en segundo plano (compresión / extracción)';
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
                                        // Padding is a touch more generous than
                                        // the previous pass so the button reads
                                        // as a real control instead of a faded
                                        // gray ghost. 0.5rem vertical + 0.875rem
                                        // horizontal matches what most of
                                        // Coolify's inline buttons use.
                                        const common = 'display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 0.875rem;line-height:1;font-size:0.75rem;font-weight:600;border-radius:0.375rem;border:1px solid transparent;cursor:pointer;';
                                        if (this.tasks.length === 0) {
                                            // Idle but visible. Slightly lighter
                                            // background (#3f3f46 instead of
                                            // #27272a), a near-white foreground
                                            // (#e4e4e7), a subtle purple border
                                            // tint to match Coolify's accent,
                                            // and NO opacity dimming — the old
                                            // 65% opacity made it read as
                                            // "disabled" which isn't the intent.
                                            return common + 'background-color:#3f3f46;color:#e4e4e7;border-color:#52525b;box-shadow:0 1px 2px rgba(0,0,0,0.3);';
                                        }
                                        if (this.failedCount > 0) {
                                            return common + 'background-color:#dc2626;color:#ffffff;border-color:#b91c1c;box-shadow:0 1px 3px rgba(220,38,38,0.4);';
                                        }
                                        if (this.runningCount > 0) {
                                            return common + 'background-color:#f59e0b;color:#ffffff;border-color:#d97706;box-shadow:0 1px 3px rgba(245,158,11,0.4);animation:ct-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;';
                                        }
                                        return common + 'background-color:#16a34a;color:#ffffff;border-color:#15803d;box-shadow:0 1px 3px rgba(22,163,74,0.4);';
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
                                    // Small colored pill that identifies
                                    // whether the row is a compression or
                                    // an extraction task. Older cached rows
                                    // don't have a task_type field — they
                                    // default to "compression" so nothing
                                    // breaks during the transition window.
                                    taskKindPillStyle(task) {
                                        const common = 'flex-shrink:0;display:inline-block;border-radius:0.25rem;padding:0.0625rem 0.375rem;font-size:0.5625rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;text-transform:uppercase;border-width:1px;border-style:solid;letter-spacing:0.05em;';
                                        const kind = task?.task_type || 'compression';
                                        if (kind === 'extraction') {
                                            return common + 'background-color:rgba(59,130,246,0.15);color:#60a5fa;border-color:rgba(59,130,246,0.35);';
                                        }
                                        return common + 'background-color:rgba(139,92,246,0.15);color:#c4b5fd;border-color:rgba(139,92,246,0.35);';
                                    },
                                    taskKindLabel(task) {
                                        const kind = task?.task_type || 'compression';
                                        return kind === 'extraction' ? 'EXTRAER' : 'COMPRIMIR';
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
                                    // Format an integer number of seconds as a
                                    // compact "1m 23s" / "2h 5m" / "45s" label
                                    // shown next to completed/failed tasks so
                                    // the user can see how long an extraction
                                    // actually took.
                                    formatDuration(seconds) {
                                        const s = Math.max(0, Math.round(Number(seconds) || 0));
                                        if (s < 60) return `${s}s`;
                                        const m = Math.floor(s / 60);
                                        const remS = s % 60;
                                        if (m < 60) return remS > 0 ? `${m}m ${remS}s` : `${m}m`;
                                        const h = Math.floor(m / 60);
                                        const remM = m % 60;
                                        return remM > 0 ? `${h}h ${remM}m` : `${h}h`;
                                    },
                                    // Composite label rendered under each task
                                    // row. RUNNING tasks show only the relative
                                    // creation time. COMPLETED/FAILED tasks
                                    // show "hace 3 min · duración 1m 23s" so
                                    // the user can both date the task and see
                                    // how long it actually ran.
                                    taskTimeLabel(task) {
                                        if (!task?.created_at) return '';
                                        const rel = this.relativeTime(task.created_at);
                                        const status = task?.status || 'running';
                                        if (status === 'running') return rel;
                                        const dur = task?.duration_seconds;
                                        if (dur === null || dur === undefined || dur === '') return rel;
                                        return `${rel} · duración ${this.formatDuration(dur)}`;
                                    },
                                };
                            };
                        </script>

                        {{-- Alerts bell dropdown. Sibling of the
                             compression tasks button, anchored at
                             right:0 so it ends up to the RIGHT of
                             tasks (which was shifted to
                             right:4.5rem above). Same Alpine
                             polling pattern as compression tasks:
                             30s closed, 10s open, fetch() against
                             /monitor/alerts.json. Entirely hidden
                             for client users — they do not see
                             the /monitor page and have no use for
                             a global alerts view.

                             Every style attribute here is INLINE
                             on purpose so the button renders
                             correctly even if Tailwind's compiled
                             bundle is stale on the server. See
                             the compression tasks block above
                             for the full rationale. --}}
                        <div
                            class="z-40"
                            style="position:absolute;right:0;top:0;"
                            x-data="alertsBellPanel()"
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
                                    {{-- Bell icon --}}
                                    <svg width="14" height="14" style="width:14px;height:14px;flex-shrink:0;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-4-5.7V5a2 2 0 10-4 0v.3A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0"/>
                                    </svg>
                                    <span x-text="buttonLabel()"></span>
                                    {{-- Mini red dot when critical count > 0 --}}
                                    <span x-show="criticalCount > 0" x-cloak style="display:inline-block;width:0.375rem;height:0.375rem;border-radius:9999px;background-color:#ef4444;animation:ct-pulse 2s ease-in-out infinite;"></span>
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
                                    style="position:absolute;right:0;top:calc(100% + 0.5rem);width:26rem;max-width:calc(100vw - 2rem);max-height:30rem;overflow:auto;background-color:#18181b;border-color:#27272a;color:#e4e4e7;"
                                >
                                    <div class="flex items-center justify-between gap-2 border-b px-4 py-3" style="border-color:#27272a;">
                                        <div class="min-w-0">
                                            <h4 class="text-sm font-semibold" style="color:#ffffff;">Alertas del sistema</h4>
                                            <p class="mt-0.5 text-[11px]" style="color:#71717a;">
                                                <span x-show="criticalCount > 0" x-cloak><span style="color:#f87171;font-weight:600;" x-text="criticalCount"></span> críticas · </span>
                                                <span x-show="warningCount > 0" x-cloak><span style="color:#fbbf24;font-weight:600;" x-text="warningCount"></span> advertencias · </span>
                                                actualización cada 10s
                                            </p>
                                        </div>
                                        <a href="/monitor" wire:navigate
                                           style="display:inline-block;padding:0.25rem 0.6rem;font-size:0.6875rem;font-weight:600;border-radius:0.25rem;background-color:rgba(139,92,246,0.15);color:#c4b5fd;border:1px solid rgba(139,92,246,0.35);text-decoration:none;">
                                            Ver monitor
                                        </a>
                                    </div>

                                    {{-- Empty state --}}
                                    <template x-if="total === 0 && !loading">
                                        <div class="flex flex-col items-center justify-center px-4 py-10 text-center">
                                            <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:40px;height:40px;margin-bottom:0.5rem;color:#22c55e;display:block;">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <p class="text-sm" style="color:#a1a1aa;">Todo funcionando correctamente</p>
                                            <p class="mt-0.5 text-[11px]" style="color:#52525b;">No hay recursos con problemas en este momento.</p>
                                        </div>
                                    </template>

                                    {{-- Loading state --}}
                                    <template x-if="total === 0 && loading">
                                        <div class="px-4 py-6 text-center">
                                            <p class="text-xs" style="color:#71717a;">Cargando alertas…</p>
                                        </div>
                                    </template>

                                    {{-- Alerts list --}}
                                    <template x-if="total > 0">
                                        <ul style="list-style:none;margin:0;padding:0;">
                                            <template x-for="item in items" :key="item.id + '-' + item.type">
                                                <li :style="'padding:0.7rem 1rem;border-top:1px solid #27272a;border-left:' + (item.severity === 'critical' ? '3px solid #ef4444' : '3px solid #f59e0b') + ';'">
                                                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;">
                                                        <div style="min-width:0;flex:1 1 0%;">
                                                            <div style="display:flex;align-items:center;gap:0.4rem;">
                                                                <span :style="'display:inline-block;padding:0.05rem 0.4rem;border-radius:0.25rem;font-size:0.5625rem;font-weight:700;text-transform:uppercase;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;' + (item.severity === 'critical' ? 'background-color:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.35);' : 'background-color:rgba(245,158,11,0.15);color:#fbbf24;border:1px solid rgba(245,158,11,0.35);')" x-text="item.severity === 'critical' ? 'CRÍTICO' : 'AVISO'"></span>
                                                                <span style="font-size:0.8125rem;font-weight:700;color:#ffffff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="item.name"></span>
                                                            </div>
                                                            <div style="margin-top:0.2rem;font-size:0.625rem;color:#a1a1aa;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;" x-text="item.status"></div>
                                                            <div style="margin-top:0.1rem;font-size:0.625rem;color:#71717a;">
                                                                <span x-text="item.kind_label"></span>
                                                                <span> · </span>
                                                                <span x-text="item.server_name"></span>
                                                                <template x-if="item.project_name">
                                                                    <span>
                                                                        <span> · </span>
                                                                        <span x-text="item.project_name"></span>
                                                                    </span>
                                                                </template>
                                                            </div>
                                                        </div>
                                                        <a :href="item.url" wire:navigate
                                                           style="display:inline-block;padding:0.2rem 0.5rem;font-size:0.625rem;font-weight:600;border-radius:0.25rem;background-color:rgba(139,92,246,0.12);color:#c4b5fd;border:1px solid rgba(139,92,246,0.3);text-decoration:none;flex-shrink:0;">
                                                            Abrir
                                                        </a>
                                                    </div>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <script>
                            window.alertsBellPanel = window.alertsBellPanel || function () {
                                return {
                                    open: false,
                                    loading: false,
                                    total: 0,
                                    criticalCount: 0,
                                    warningCount: 0,
                                    items: [],
                                    pollHandle: null,
                                    init() {
                                        this.refresh();
                                        this.schedulePoll();
                                    },
                                    schedulePoll() {
                                        if (this.pollHandle) {
                                            clearInterval(this.pollHandle);
                                        }
                                        const interval = this.open ? 10000 : 30000;
                                        this.pollHandle = setInterval(() => this.refresh(true), interval);
                                    },
                                    async refresh(silent = false) {
                                        if (!silent) {
                                            this.loading = true;
                                        }
                                        try {
                                            const res = await fetch('{{ route('monitor.alerts') }}', {
                                                method: 'GET',
                                                headers: { 'Accept': 'application/json' },
                                                credentials: 'same-origin',
                                            });
                                            if (!res.ok) return;
                                            const payload = await res.json();
                                            this.total = Number(payload.total || 0);
                                            this.criticalCount = Number(payload.critical || 0);
                                            this.warningCount = Number(payload.warning || 0);
                                            this.items = Array.isArray(payload.items) ? payload.items : [];
                                            this.schedulePoll();
                                        } catch (e) {
                                            // ignore
                                        } finally {
                                            this.loading = false;
                                        }
                                    },
                                    buttonLabel() {
                                        if (this.total === 0) return 'Alertas';
                                        return `${this.total} ${this.total === 1 ? 'alerta' : 'alertas'}`;
                                    },
                                    buttonTitle() {
                                        if (this.criticalCount > 0) return `${this.criticalCount} alertas críticas. Haz clic para ver detalles.`;
                                        if (this.warningCount > 0) return `${this.warningCount} avisos.`;
                                        return 'Alertas del sistema — todo OK';
                                    },
                                    buttonStyle() {
                                        const base = 'display:inline-flex;align-items:center;gap:0.4rem;padding:0.35rem 0.65rem;border-radius:0.375rem;font-size:0.75rem;line-height:1;border-width:1px;border-style:solid;transition:background-color 0.15s ease,border-color 0.15s ease;';
                                        if (this.criticalCount > 0) {
                                            return base + 'background-color:rgba(239,68,68,0.18);color:#fca5a5;border-color:rgba(239,68,68,0.5);animation:ct-pulse 2s ease-in-out infinite;';
                                        }
                                        if (this.warningCount > 0) {
                                            return base + 'background-color:rgba(245,158,11,0.18);color:#fbbf24;border-color:rgba(245,158,11,0.5);';
                                        }
                                        return base + 'background-color:#18181b;color:#a1a1aa;border-color:#27272a;';
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
