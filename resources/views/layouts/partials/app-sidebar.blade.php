@php
    $codes = $identityPermissions ?? [];
    $has = static fn (string $code): bool => in_array($code, $codes, true);
    $P = \App\Modules\Identity\PermissionCatalog::class;
    $identityUser = request()->attributes->get('identity');

    $showP1 = $has($P::MANAGE_ACCOUNTS) || $has($P::CONSULT_ACTIVITY_LOG);
    $showP2 = $has($P::MANAGE_PATIENTS);
    $showP3 = $has($P::MANAGE_PHYSICIANS) || $has($P::MANAGE_SPECIALTIES);
    $showP4 = $has($P::MANAGE_SCHEDULES);
    $showP5 = $has($P::CONSULT_AVAILABILITY) || $has($P::BOOK_APPOINTMENTS) || $has($P::RESCHEDULE_APPOINTMENTS);
    $showP6 = $has($P::CONSULT_AGENDA) || $has($P::CONSULT_HISTORY) || $has($P::GENERATE_REPORTS);
    $showP7 = $has($P::UPDATE_PROFILE) || $has($P::DECLARE_HEALTH) || $has($P::CONSULT_CLINICAL_HISTORY);

    $openP1 = request()->routeIs('accounts.*', 'activity-log.*');
    $openP2 = request()->routeIs('patients.*') && ! request()->routeIs('patients.declared');
    $openP3 = request()->routeIs('physicians.*', 'specialties.*');
    $openP4 = request()->routeIs('schedules.*');
    $openP5 = request()->routeIs(
        'availability.*',
        'appointments.index',
        'appointments.show',
        'appointments.create',
        'appointments.store',
        'appointments.reschedule',
        'appointments.reschedule.store',
        'appointments.cancel',
        'appointments.cancel.store',
        'appointments.attendance',
        'appointments.confirm',
        'appointments.arrival',
        'appointments.absence',
    );
    $openP6 = request()->routeIs('appointments.agenda', 'appointments.attend', 'appointments.attend.store', 'appointments.history', 'reports.*');
    $openP7 = request()->routeIs('portal.profile', 'patients.declared', 'appointments.clinical-history', 'appointments.clinical-history.show');
@endphp
<aside class="app-sidebar" aria-label="Paquetes del sistema">
    <a class="login-logo app-brand" href="{{ route('panel') }}" aria-label="Ir al panel">
        <span class="brand-badge" aria-hidden="true">+</span>
        <span>Clínica Vida Salud</span>
    </a>

    <div class="app-menu" id="app-navigation">
        <input class="app-menu-switch" type="checkbox" id="app-menu-switch" autocomplete="off">
        <label class="app-menu-toggle" for="app-menu-switch">Menú de navegación</label>
        <div class="app-menu-content">
    <nav class="app-nav">
        <a class="app-nav-link app-nav-panel @if (request()->routeIs('panel')) is-current @endif" href="{{ route('panel') }}">
            <span class="app-nav-panel-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M4 4h7v7H4V4Zm9 0h7v5h-7V4ZM4 13h7v7H4v-7Zm9 3h7v4h-7v-4Z"/></svg>
            </span>
            Panel
        </a>

        @if ($showP1)
            <details class="app-nav-package" @if ($openP1) open @endif>
                <summary class="app-nav-package-title">P1 · Autenticación y usuarios</summary>
                <div class="app-nav-package-links">
                    @if ($has($P::MANAGE_ACCOUNTS))
                        <a class="app-nav-link @if (request()->routeIs('accounts.*')) is-current @endif" href="{{ route('accounts.index') }}">Usuarios</a>
                    @endif
                    @if ($has($P::CONSULT_ACTIVITY_LOG))
                        <a class="app-nav-link @if (request()->routeIs('activity-log.*')) is-current @endif" href="{{ route('activity-log.index') }}">Bitácora</a>
                    @endif
                </div>
            </details>
        @endif

        @if ($showP2)
            <details class="app-nav-package" @if ($openP2) open @endif>
                <summary class="app-nav-package-title">P2 · Gestión de pacientes</summary>
                <div class="app-nav-package-links">
                    <a class="app-nav-link @if ($openP2) is-current @endif" href="{{ route('patients.index') }}">Pacientes</a>
                </div>
            </details>
        @endif

        @if ($showP3)
            <details class="app-nav-package" @if ($openP3) open @endif>
                <summary class="app-nav-package-title">P3 · Gestión de médicos y especialidades</summary>
                <div class="app-nav-package-links">
                    @if ($has($P::MANAGE_PHYSICIANS))
                        <a class="app-nav-link @if (request()->routeIs('physicians.*')) is-current @endif" href="{{ route('physicians.index') }}">Médicos</a>
                    @endif
                    @if ($has($P::MANAGE_SPECIALTIES))
                        <a class="app-nav-link @if (request()->routeIs('specialties.*')) is-current @endif" href="{{ route('specialties.index') }}">Especialidades</a>
                    @endif
                </div>
            </details>
        @endif

        @if ($showP4)
            <details class="app-nav-package" @if ($openP4) open @endif>
                <summary class="app-nav-package-title">P4 · Gestión de horarios de atención</summary>
                <div class="app-nav-package-links">
                    <a class="app-nav-link @if (request()->routeIs('schedules.*')) is-current @endif" href="{{ route('schedules.index') }}">Horarios</a>
                </div>
            </details>
        @endif

        @if ($showP5)
            <details class="app-nav-package" @if ($openP5) open @endif>
                <summary class="app-nav-package-title">P5 · Gestión de citas médicas</summary>
                <div class="app-nav-package-links">
                    @if ($has($P::CONSULT_AVAILABILITY))
                        <a class="app-nav-link @if (request()->routeIs('availability.consult')) is-current @endif" href="{{ route('availability.consult') }}">Disponibilidad</a>
                    @endif
                    @if ($has($P::RESCHEDULE_APPOINTMENTS))
                        <a class="app-nav-link @if (request()->routeIs('appointments.index', 'appointments.show', 'appointments.create', 'appointments.reschedule', 'appointments.cancel', 'appointments.attendance', 'appointments.attend')) is-current @endif" href="{{ route('appointments.index') }}">Citas</a>
                    @elseif ($has($P::BOOK_APPOINTMENTS))
                        <a class="app-nav-link @if (request()->routeIs('appointments.*') && ! request()->routeIs('appointments.agenda', 'appointments.history', 'appointments.clinical-history', 'appointments.clinical-history.show')) is-current @endif" href="{{ route('appointments.create') }}">Citas</a>
                    @endif
                </div>
            </details>
        @endif

        @if ($showP6)
            <details class="app-nav-package" @if ($openP6) open @endif>
                <summary class="app-nav-package-title">P6 · Consultas y reportes</summary>
                <div class="app-nav-package-links">
                    @if ($has($P::CONSULT_AGENDA) || $has($P::MARK_ATTENDED))
                        <a class="app-nav-link @if (request()->routeIs('appointments.agenda')) is-current @endif" href="{{ route('appointments.agenda') }}">Agenda</a>
                    @endif
                    @if ($has($P::CONSULT_HISTORY))
                        <a class="app-nav-link @if (request()->routeIs('appointments.history')) is-current @endif" href="{{ route('appointments.history') }}">Historial</a>
                    @endif
                    @if ($has($P::GENERATE_REPORTS))
                        <a class="app-nav-link @if (request()->routeIs('reports.*')) is-current @endif" href="{{ route('reports.generate') }}">Reportes</a>
                    @endif
                </div>
            </details>
        @endif

        @if ($showP7)
            <details class="app-nav-package" @if ($openP7) open @endif>
                <summary class="app-nav-package-title">P7 · Perfil e información médica del paciente</summary>
                <div class="app-nav-package-links">
                    @if ($has($P::UPDATE_PROFILE))
                        <a class="app-nav-link @if (request()->routeIs('portal.profile')) is-current @endif" href="{{ route('portal.profile') }}">Perfil</a>
                    @endif
                    @if ($has($P::DECLARE_HEALTH))
                        <a class="app-nav-link @if (request()->routeIs('patients.declared')) is-current @endif" href="{{ route('patients.declared') }}">Información médica</a>
                    @endif
                    @if ($has($P::CONSULT_CLINICAL_HISTORY))
                        <a class="app-nav-link @if (request()->routeIs('appointments.clinical-history', 'appointments.clinical-history.show')) is-current @endif" href="{{ route('appointments.clinical-history') }}">Historial médico</a>
                    @endif
                </div>
            </details>
        @endif
    </nav>

    <div class="app-sidebar-end">
        @if ($identityUser?->nombre_usuario)
            <p class="app-session-name">{{ $identityUser->nombre_usuario }}</p>
        @endif
        <form method="post" action="{{ route('logout', absolute: false) }}">
            @csrf
            <button class="app-nav-link app-logout" type="submit">Cerrar sesión</button>
        </form>
    </div>
        </div>
    </div>
</aside>
