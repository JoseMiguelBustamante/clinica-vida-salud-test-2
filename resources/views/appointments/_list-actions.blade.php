@php
    /** @var object $appointment */
    $canOpen = $canOpen ?? false;
    $canReschedule = $canReschedule ?? false;
    $canCancel = $canCancel ?? false;
    $canAttendance = $canAttendance ?? false;
    $canAttend = $canAttend ?? false;
    $hasMenu = $canCancel || $canAttendance || $canAttend;
@endphp
<nav class="row-actions" aria-label="Acciones de {{ $appointment->codigo }}">
    @if ($canOpen)
        <a class="row-action" href="{{ route('appointments.show', $appointment->id_cita) }}">Ver</a>
    @endif
    @if ($canReschedule)
        <a class="row-action" href="{{ route('appointments.reschedule', $appointment->id_cita) }}">Reprogramar</a>
    @endif
    @if ($hasMenu)
        <details class="row-menu">
            <summary aria-label="Más acciones de {{ $appointment->codigo }}">⋯</summary>
            <div class="row-menu-list">
                @if ($canAttendance)
                    <a href="{{ route('appointments.attendance', $appointment->id_cita) }}">Registrar asistencia</a>
                @endif
                @if ($canAttend)
                    <a href="{{ route('appointments.attend', $appointment->id_cita) }}">Marcar atendida</a>
                @endif
                @if ($canCancel)
                    <a class="is-danger" href="{{ route('appointments.cancel', $appointment->id_cita) }}">Cancelar cita</a>
                @endif
            </div>
        </details>
    @endif
</nav>
