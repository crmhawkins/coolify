<div wire:poll.10s>
    <h1>Backups</h1>
    <div class="pb-4 dark:text-neutral-400">
        Respaldos de todos los proyectos del equipo — bases de datos y archivos — con destino local y servidor externo por SFTP.
    </div>

    {{-- ============================================================
         Tabs header
         ============================================================ --}}
    <div class="flex items-center gap-4 pb-6 border-b border-coolgray-200">
        <button type="button"
            wire:click="$set('activeTab', 'local')"
            @class([
                'pb-2 text-sm font-semibold transition-colors',
                'dark:text-white border-b-2 border-coollabs' => $activeTab === 'local',
                'dark:text-neutral-400 hover:dark:text-white' => $activeTab !== 'local',
            ])>
            Backup Local
        </button>
        <button type="button"
            wire:click="$set('activeTab', 'sftp')"
            @class([
                'pb-2 text-sm font-semibold transition-colors',
                'dark:text-white border-b-2 border-coollabs' => $activeTab === 'sftp',
                'dark:text-neutral-400 hover:dark:text-white' => $activeTab !== 'sftp',
            ])>
            Backup Servidor Hawkins (SFTP)
        </button>
        <button type="button"
            wire:click="$set('activeTab', 'history')"
            @class([
                'pb-2 text-sm font-semibold transition-colors',
                'dark:text-white border-b-2 border-coollabs' => $activeTab === 'history',
                'dark:text-neutral-400 hover:dark:text-white' => $activeTab !== 'history',
            ])>
            Historial
        </button>
        <button type="button"
            wire:click="$set('activeTab', 'notify')"
            @class([
                'pb-2 text-sm font-semibold transition-colors',
                'dark:text-white border-b-2 border-coollabs' => $activeTab === 'notify',
                'dark:text-neutral-400 hover:dark:text-white' => $activeTab !== 'notify',
            ])>
            Notificaciones
        </button>
    </div>

    {{-- ============================================================
         TAB: Backup Local
         ============================================================ --}}
    @if ($activeTab === 'local')
        <div class="pt-6 flex flex-col gap-4">
            <div class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="local_enabled" wire:change="saveLocal" id="local_enabled" />
                <label for="local_enabled" class="text-sm font-semibold dark:text-white">Backup local activado</label>
            </div>
            <div class="text-xs dark:text-neutral-400 -mt-2">
                Cuando está activado, se ejecuta según la programación de abajo y se puede lanzar a mano con "Ejecutar ahora".
            </div>

            <div class="grid md:grid-cols-2 gap-4 pt-4">
                <x-forms.select wire:model="local_scope" wire:change="saveLocal" label="Qué incluir" id="local_scope"
                    helper="Completo: bases de datos + archivos. Puedes limitar a solo uno para backups más rápidos.">
                    <option value="full">Completo (bases de datos + archivos)</option>
                    <option value="databases">Solo bases de datos</option>
                    <option value="files">Solo archivos</option>
                </x-forms.select>
                <x-forms.select wire:model="local_file_mode" wire:change="saveLocal" label="Qué archivos" id="local_file_mode"
                    helper="Volúmenes persistentes: solo lo que has declarado como persistente. Contenedor completo: todo /var/www/html de cada contenedor (más pesado, captura .env y ficheros escritos a mano).">
                    <option value="persistent">Volúmenes persistentes declarados</option>
                    <option value="full-container">Contenedor completo (/var/www/html)</option>
                </x-forms.select>
                <x-forms.input wire:model="local_cron" label="Programación (cron)" id="local_cron"
                    helper="Por defecto viernes 22:00 ({{ '0 22 * * 5' }}). Cinco campos cron estándar: minuto hora día-mes mes día-semana." />
                <x-forms.input type="number" min="1" max="60" wire:model="local_retention" label="Retener últimos N backups" id="local_retention"
                    helper="Los backups más antiguos se borran automáticamente al completar uno nuevo." />
                <x-forms.input wire:model="local_path" label="Ruta en el servidor Coolify" id="local_path"
                    helper="Los tarballs .tar.gz se guardan aquí. Cambiar requiere que la ruta exista y sea escribible." />
            </div>

            <div class="text-xs dark:text-neutral-400 pt-1">
                Zona horaria actual: <code>{{ $this->localTimezone }}</code>
                @if ($this->localTimezone !== 'Europe/Madrid')
                    — si necesitas horario España, cambia <code>APP_TIMEZONE=Europe/Madrid</code> en la configuración de la instancia.
                @else
                    (España ✓)
                @endif
            </div>

            <div class="pt-2">
                <x-forms.button wire:click="saveLocal">Guardar cambios del backup local</x-forms.button>
            </div>

            {{-- Estimation + disk free ------------------------------------------ --}}
            <div class="mt-4 rounded-lg p-4 bg-coolgray-100 border border-coolgray-200">
                <div class="flex items-center justify-between gap-3 pb-3">
                    <div class="text-sm font-semibold dark:text-white">Estimación de tamaño y espacio</div>
                    <x-forms.button wire:click="estimate" wire:loading.attr="disabled" wire:target="estimate">
                        <span wire:loading.remove wire:target="estimate">Calcular</span>
                        <span wire:loading wire:target="estimate">Calculando…</span>
                    </x-forms.button>
                </div>
                <ul class="text-xs dark:text-neutral-300 space-y-1">
                    <li><strong>Espacio libre en disco:</strong>
                        @if ($hostFreeBytes !== null)
                            <code>{{ number_format($hostFreeBytes / 1073741824, 2) }} GB</code>
                        @else
                            <span class="dark:text-neutral-500">desconocido</span>
                        @endif
                    </li>
                    <li><strong>Tamaño estimado del backup:</strong>
                        @if ($estimatedTotalBytes !== null)
                            <code>{{ number_format($estimatedTotalBytes / 1073741824, 2) }} GB</code>
                            <span class="dark:text-neutral-500">({{ $estimatedDbCount }} bases de datos, {{ $estimatedFileCount }} volúmenes)</span>
                        @else
                            <span class="dark:text-neutral-500">pulsa "Calcular"</span>
                        @endif
                    </li>
                    <li><strong>Tiempo estimado del backup local:</strong>
                        @if ($this->etaLocalSeconds)
                            <code>~{{ gmdate('H:i:s', $this->etaLocalSeconds) }}</code>
                            <span class="dark:text-neutral-500">(media de las últimas ejecuciones)</span>
                        @else
                            <span class="dark:text-neutral-500">primera ejecución, tiempo desconocido</span>
                        @endif
                    </li>
                </ul>
                @if ($estimatedTotalBytes !== null && $hostFreeBytes !== null && $estimatedTotalBytes > $hostFreeBytes)
                    <div class="mt-3 p-2 text-xs rounded bg-red-900/30 border border-red-700 dark:text-red-300">
                        ⚠ El backup estimado es mayor que el espacio libre. Libera espacio antes de ejecutarlo.
                    </div>
                @endif
            </div>

            <div class="pt-4">
                <x-forms.button wire:click="runNow" wire:loading.attr="disabled" wire:target="runNow" class="bg-coollabs">
                    <span wire:loading.remove wire:target="runNow">Ejecutar backup ahora</span>
                    <span wire:loading wire:target="runNow">Encolando…</span>
                </x-forms.button>
                <span class="ml-2 text-xs dark:text-neutral-400">
                    Si SFTP está activado, se encadenará automáticamente al acabar el local.
                </span>
            </div>
        </div>
    @endif

    {{-- ============================================================
         TAB: Backup SFTP
         ============================================================ --}}
    @if ($activeTab === 'sftp')
        <div class="pt-6 flex flex-col gap-4">
            <div class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="sftp_enabled" wire:change="saveSftp" id="sftp_enabled" />
                <label for="sftp_enabled" class="text-sm font-semibold dark:text-white">Subida SFTP activada</label>
            </div>
            <div class="text-xs dark:text-neutral-400 -mt-2">
                Sube el tarball generado por el backup local a un servidor externo por SFTP. Solo se ejecuta cuando el backup local termina correctamente.
            </div>

            <div class="grid md:grid-cols-2 gap-4 pt-4">
                <x-forms.input wire:model="sftp_host" label="Host / IP del servidor" id="sftp_host"
                    placeholder="sftp.miempresa.com" helper="Nombre DNS o IP del servidor destino." />
                <x-forms.input type="number" min="1" max="65535" wire:model="sftp_port" label="Puerto" id="sftp_port"
                    helper="Puerto SSH del servidor (normalmente 22)." />
                <x-forms.input wire:model="sftp_username" label="Usuario" id="sftp_username"
                    placeholder="backups" helper="Usuario SFTP con permisos de escritura en la ruta remota." />
                <x-forms.input wire:model="sftp_remote_path" label="Ruta remota" id="sftp_remote_path"
                    placeholder="/backups/coolify" helper="Directorio absoluto donde se subirán los .tar.gz (se creará si no existe)." />
                <x-forms.input type="number" min="1" max="60" wire:model="sftp_retention" label="Retener últimos N backups remotos" id="sftp_retention"
                    helper="Los más antiguos se borran del servidor remoto tras cada subida." />
                <x-forms.select wire:model.live="sftp_auth_method" label="Método de autenticación" id="sftp_auth_method"
                    helper="Password = clásico usuario/contraseña. Clave SSH = pegas la clave privada (más seguro).">
                    <option value="password">Password</option>
                    <option value="key">Clave SSH (privada)</option>
                </x-forms.select>
            </div>

            @if ($sftp_auth_method === 'password')
                <div class="w-full md:max-w-md">
                    <x-forms.input type="password" wire:model="sftp_password" label="Contraseña SFTP"
                        placeholder="Dejar en blanco para mantener la guardada"
                        helper="Solo se guarda si escribes algo nuevo. La contraseña se almacena cifrada." />
                </div>
            @else
                <div class="flex flex-col gap-3">
                    <div>
                        <label class="text-sm font-medium dark:text-white">Clave privada SSH</label>
                        <textarea wire:model="sftp_private_key" rows="8" class="w-full text-xs font-mono rounded-md border-coolgray-300 dark:bg-coolgray-100 dark:border-coolgray-300 dark:text-white"
                            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"></textarea>
                        <div class="text-xs dark:text-neutral-400 mt-1">
                            Dejar en blanco para mantener la clave ya guardada. Se almacena cifrada.
                        </div>
                    </div>
                    <div class="w-full md:max-w-md">
                        <x-forms.input type="password" wire:model="sftp_private_key_passphrase" label="Passphrase de la clave (si tiene)"
                            placeholder="Dejar en blanco si la clave no tiene passphrase" />
                    </div>
                </div>
            @endif

            <div class="pt-2 flex items-center gap-2">
                <x-forms.button wire:click="saveSftp">Guardar cambios SFTP</x-forms.button>
                <x-forms.button wire:click="testSftpConnection" wire:loading.attr="disabled" wire:target="testSftpConnection">
                    <span wire:loading.remove wire:target="testSftpConnection">Probar conexión</span>
                    <span wire:loading wire:target="testSftpConnection">Probando…</span>
                </x-forms.button>
            </div>

            <div class="mt-4 rounded-lg p-4 bg-coolgray-100 border border-coolgray-200">
                <div class="text-sm font-semibold dark:text-white pb-2">Tiempo estimado del backup SFTP</div>
                <div class="text-xs dark:text-neutral-300">
                    @if ($this->etaSftpSeconds)
                        <code>~{{ gmdate('H:i:s', $this->etaSftpSeconds) }}</code>
                        <span class="dark:text-neutral-500">(media de las últimas ejecuciones)</span>
                    @else
                        <span class="dark:text-neutral-500">primera ejecución, tiempo desconocido (depende del ancho de subida a tu servidor)</span>
                    @endif
                </div>
            </div>

            <div class="mt-6 p-4 rounded-lg bg-blue-900/20 border border-blue-700/50 text-xs dark:text-blue-200">
                <strong>¿Cómo generar una clave SSH para Hawkins?</strong><br>
                En tu ordenador local, ejecuta:<br>
                <code class="block mt-2 p-2 bg-black/30 rounded">ssh-keygen -t ed25519 -f ~/.ssh/coolify_hawkins -C "coolify-backups"</code>
                Eso crea dos ficheros: <code>coolify_hawkins</code> (privada, pégala arriba) y <code>coolify_hawkins.pub</code> (pública). Copia el contenido de la pública al servidor Hawkins dentro de <code>~/.ssh/authorized_keys</code> del usuario SFTP. Después pulsa "Probar conexión" para comprobar que funciona.
            </div>
        </div>
    @endif

    {{-- ============================================================
         TAB: Historial
         ============================================================ --}}
    @if ($activeTab === 'history')
        <div class="pt-6 flex flex-col gap-3">
            <div class="text-sm dark:text-neutral-400">Últimos 25 registros (se refresca cada 10s).</div>
            @if ($this->runs->isEmpty())
                <div class="text-sm dark:text-neutral-500 p-4 rounded border border-coolgray-300">
                    Aún no hay ejecuciones. Lanza una manualmente desde la pestaña "Backup Local" o espera a la próxima programada.
                </div>
            @else
                <div class="divide-y divide-coolgray-200">
                    @foreach ($this->runs as $run)
                        <div class="py-3 flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span @class([
                                        'text-[10px] font-bold uppercase px-2 py-0.5 rounded',
                                        'bg-emerald-900/50 text-emerald-300' => $run->status === 'completed',
                                        'bg-amber-900/50 text-amber-300' => in_array($run->status, ['pending', 'running']),
                                        'bg-red-900/50 text-red-300' => $run->status === 'failed',
                                        'bg-gray-700 text-gray-300' => in_array($run->status, ['skipped', 'pruned']),
                                    ])>{{ $run->status }}</span>
                                    <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-blue-900/50 text-blue-300">
                                        {{ $run->destination === 'local' ? 'LOCAL' : 'SFTP' }}
                                    </span>
                                    <span class="text-[10px] uppercase px-2 py-0.5 rounded bg-coolgray-200 dark:text-neutral-300">
                                        {{ $run->scope }}
                                    </span>
                                    <span class="text-[10px] uppercase px-2 py-0.5 rounded bg-coolgray-200 dark:text-neutral-300">
                                        {{ $run->trigger }}
                                    </span>
                                </div>
                                <div class="text-xs dark:text-neutral-400 mt-1 truncate">
                                    {{ $run->last_message }}
                                </div>
                                @if ($run->artifact_path)
                                    <div class="text-[11px] font-mono dark:text-neutral-500 mt-1 truncate">
                                        {{ $run->artifact_path }}
                                    </div>
                                @endif
                                <div class="text-[11px] dark:text-neutral-500 mt-1">
                                    {{ $run->created_at?->format('Y-m-d H:i:s') }}
                                    @if ($run->durationSeconds())
                                        · duración {{ gmdate('H:i:s', $run->durationSeconds()) }}
                                    @endif
                                    @if ($run->size_bytes)
                                        · {{ number_format($run->size_bytes / 1073741824, 2) }} GB
                                    @endif
                                </div>
                            </div>
                            @if ($run->destination === 'local' && $run->status === 'completed' && $run->artifact_path)
                                <a href="{{ route('backups.download', ['id' => $run->id]) }}"
                                    class="text-xs px-3 py-1 rounded bg-coollabs text-white hover:opacity-80">
                                    Descargar
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ============================================================
         TAB: Notificaciones
         ============================================================ --}}
    @if ($activeTab === 'notify')
        <div class="pt-6 flex flex-col gap-4">
            <div class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="notify_on_failure" wire:change="saveNotifications" id="notify_on_failure" />
                <label for="notify_on_failure" class="text-sm dark:text-white">Avisar por email cuando un backup falla</label>
            </div>
            <div class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="notify_on_success" wire:change="saveNotifications" id="notify_on_success" />
                <label for="notify_on_success" class="text-sm dark:text-white">Avisar también cuando un backup termina bien</label>
            </div>

            <div class="pt-3">
                <label class="text-sm font-semibold dark:text-white">Destinatarios</label>
                <div class="text-xs dark:text-neutral-400 pb-2">
                    Emails que recibirán los avisos. Puedes añadir o quitar los que quieras.
                </div>
                <ul class="flex flex-col gap-1">
                    @foreach ($notification_emails as $email)
                        <li class="flex items-center justify-between gap-3 p-2 rounded bg-coolgray-100 border border-coolgray-200">
                            <span class="text-xs font-mono dark:text-white">{{ $email }}</span>
                            <button type="button" wire:click="removeEmail('{{ $email }}')"
                                class="text-[11px] text-red-400 hover:text-red-300">Quitar</button>
                        </li>
                    @endforeach
                </ul>
                <div class="flex items-end gap-2 pt-3 max-w-md">
                    <div class="flex-1">
                        <x-forms.input wire:model="new_email" label="Añadir correo" placeholder="alguien@empresa.com" id="new_email" />
                    </div>
                    <x-forms.button wire:click="addEmail">Añadir</x-forms.button>
                </div>
            </div>
        </div>
    @endif
</div>
