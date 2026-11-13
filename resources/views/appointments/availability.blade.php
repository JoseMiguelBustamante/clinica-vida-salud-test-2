@extends('layouts.clinic')

@section('title', 'Disponibilidad — Clínica Vida Salud')

@php
    $weekdayNames = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];
    $shortWeekdays = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];
    $monthNames = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $boardQuery = array_filter([
        'especialidad' => $especialidad,
        'medico' => $medico,
        'estado' => $estado,
        'vista' => $vista,
        'escala' => $escala === 'normal' ? null : $escala,
    ], static fn ($value) => $value !== null && $value !== '');
    $bookingKeep = array_filter([
        'id_paciente' => $id_paciente,
        'q' => $q,
        'hora_inicio' => $bookingOpen ? $hora_inicio : null,
        'hora_fin' => $bookingOpen ? $hora_fin : null,
        'nueva' => $bookingOpen ? 1 : null,
    ], static fn ($value) => $value !== null && $value !== '');
    $queryBase = $boardQuery + $bookingKeep;
    $codes = $identityPermissions ?? [];
    $canBook = in_array(\App\Modules\Identity\PermissionCatalog::BOOK_APPOINTMENTS, $codes, true);
    $canOpen = $canBook || in_array(\App\Modules\Identity\PermissionCatalog::MARK_ATTENDED, $codes, true);
    $canManagePatients = in_array(\App\Modules\Identity\PermissionCatalog::MANAGE_PATIENTS, $codes, true);
    $trackHeight = ($boardEndHour - $boardStartHour) * $hourHeight;
    $eventsByDay = collect($events)->groupBy('fecha');
    $legend = collect($events)->unique('id_medico')->values();
    $monthStart = $today->copy()->startOfMonth();
    if ($vista === 'mes') {
        try {
            $parsedMonth = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $fecha, 'UTC');
            if ($parsedMonth !== false) {
                $monthStart = $parsedMonth->copy()->startOfMonth();
            }
        } catch (\Throwable) {
        }
    }
    $monthEnd = $monthStart->copy()->endOfMonth();
    $pad = $monthStart->isoWeekday() - 1;
    $slotQuery = static function (string $iso, string $start, string $end) use ($boardQuery, $bookingKeep): array {
        return array_filter($boardQuery + $bookingKeep + [
            'fecha' => $iso,
            'hora_inicio' => $start,
            'hora_fin' => $end,
            'nueva' => 1,
        ], static fn ($value) => $value !== null && $value !== '');
    };
    $closeQuery = array_filter($boardQuery + ['fecha' => $fecha], static fn ($value) => $value !== null && $value !== '');
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Disponibilidad</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Disponibilidad</h1>
                <p class="lead">Pulse un hueco del calendario para programar. El intervalo se vuelve a comprobar al guardar y no confirma la asistencia.</p>
            </div>
            @if ($canBook)
                <a class="button button-inline" href="{{ route('availability.consult', $boardQuery + $bookingKeep + ['fecha' => $fecha, 'nueva' => 1], false) }}">Nueva cita</a>
            @endif
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($status)
            <p class="form-errors" role="alert">{{ $status }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <form class="filter-bar availability-filters" method="get" action="{{ route('availability.consult', absolute: false) }}">
            <input type="hidden" name="vista" value="{{ $vista }}">
            <input type="hidden" name="fecha" value="{{ $fecha }}">
            @if ($escala !== 'normal')
                <input type="hidden" name="escala" value="{{ $escala }}">
            @endif
            @if ($bookingOpen)
                <input type="hidden" name="nueva" value="1">
                <input type="hidden" name="hora_inicio" value="{{ $hora_inicio }}">
                <input type="hidden" name="hora_fin" value="{{ $hora_fin }}">
                <input type="hidden" name="q" value="{{ $q }}">
                <input type="hidden" name="id_paciente" value="{{ $id_paciente }}">
            @endif
            <div class="field">
                <label for="medico">Médico</label>
                <select id="medico" name="medico">
                    <option value="">Todos los médicos</option>
                    @foreach ($physicians as $physician)
                        <option value="{{ $physician->id_medico }}" @selected((string) $medico === (string) $physician->id_medico)>
                            {{ $physician->nombres }} {{ $physician->apellidos }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="especialidad">Especialidad</label>
                <select id="especialidad" name="especialidad">
                    <option value="">Todas</option>
                    @foreach ($specialties as $specialty)
                        <option value="{{ $specialty->id_especialidad }}" @selected((string) $especialidad === (string) $specialty->id_especialidad)>
                            {{ $specialty->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <option value="">Todos</option>
                    @foreach (['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'] as $option)
                        <option value="{{ $option }}" @selected($estado === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <button class="button button-inline" type="submit">Consultar</button>
        </form>

        <div @class(['booking-layout', 'is-open' => $bookingOpen])>
            <div class="week-board @if ($escala === 'amplia') is-amplia @endif">
                <div class="week-toolbar">
                    <div class="week-nav">
                        <a class="week-chip" href="{{ route('availability.consult', $queryBase + ['fecha' => $today->toDateString()], false) }}">Hoy</a>
                        @if ($canPrev)
                            <a class="calendar-nav-btn" href="{{ route('availability.consult', $queryBase + ['fecha' => $prevFecha], false) }}" aria-label="Periodo anterior">‹</a>
                        @else
                            <span class="calendar-nav-btn is-disabled" aria-hidden="true">‹</span>
                        @endif
                        <a class="calendar-nav-btn" href="{{ route('availability.consult', $queryBase + ['fecha' => $nextFecha], false) }}" aria-label="Periodo siguiente">›</a>
                        <p class="week-range">{{ $rangeLabel }}</p>
                    </div>
                    <div class="week-toolbar-end">
                        <nav class="week-switch" aria-label="Altura de las horas">
                            <a class="@if ($escala === 'compacta') is-current @endif" href="{{ route('availability.consult', ['escala' => 'compacta'] + $queryBase + ['fecha' => $fecha], false) }}">Compacta</a>
                            <a class="@if ($escala === 'normal') is-current @endif" href="{{ route('availability.consult', ['escala' => 'normal'] + $queryBase + ['fecha' => $fecha], false) }}">Normal</a>
                            <a class="@if ($escala === 'amplia') is-current @endif" href="{{ route('availability.consult', ['escala' => 'amplia'] + $queryBase + ['fecha' => $fecha], false) }}">Ampliar horas</a>
                        </nav>
                        <nav class="week-switch" aria-label="Vista del calendario">
                            <a class="@if ($vista === 'dia') is-current @endif" href="{{ route('availability.consult', array_filter(['especialidad' => $especialidad, 'medico' => $medico, 'estado' => $estado, 'fecha' => $fecha, 'vista' => 'dia'] + $bookingKeep + ['escala' => $escala === 'normal' ? null : $escala]), false) }}">Día</a>
                            <a class="@if ($vista === 'semana') is-current @endif" href="{{ route('availability.consult', array_filter(['especialidad' => $especialidad, 'medico' => $medico, 'estado' => $estado, 'fecha' => $fecha, 'vista' => 'semana'] + $bookingKeep + ['escala' => $escala === 'normal' ? null : $escala]), false) }}">Semana</a>
                            <a class="@if ($vista === 'mes') is-current @endif" href="{{ route('availability.consult', array_filter(['especialidad' => $especialidad, 'medico' => $medico, 'estado' => $estado, 'fecha' => $fecha, 'vista' => 'mes'] + $bookingKeep + ['escala' => $escala === 'normal' ? null : $escala]), false) }}">Mes</a>
                        </nav>
                    </div>
                </div>

                @if ($vista === 'mes')
                    <section class="calendar-card week-month" aria-labelledby="calendar-title">
                        <h2 id="calendar-title" class="visually-hidden">{{ $rangeLabel }}</h2>
                        <div class="calendar-grid">
                            @foreach ($shortWeekdays as $weekdayName)
                                <span class="calendar-weekday">{{ $weekdayName }}</span>
                            @endforeach
                            @for ($i = 0; $i < $pad; $i++)
                                <span class="calendar-day is-empty"></span>
                            @endfor
                            @for ($day = 1; $day <= $monthEnd->day; $day++)
                                @php
                                    $cell = $monthStart->copy()->day($day);
                                    $iso = $cell->toDateString();
                                    $isPast = $cell->lt($today);
                                    $isSelected = $fecha === $iso;
                                @endphp
                                @if ($isPast)
                                    <span class="calendar-day is-past">{{ $day }}</span>
                                @elseif ($canBook)
                                    <a class="calendar-day @if ($isSelected) is-selected @endif" href="{{ route('availability.consult', ['vista' => 'dia'] + $slotQuery($iso, $hora_inicio !== '' ? $hora_inicio : '09:00', $hora_fin !== '' ? $hora_fin : '09:30'), false) }}">{{ $day }}</a>
                                @else
                                    <a class="calendar-day @if ($isSelected) is-selected @endif" href="{{ route('availability.consult', array_filter(['especialidad' => $especialidad, 'medico' => $medico, 'estado' => $estado, 'vista' => 'dia', 'fecha' => $iso]), false) }}">{{ $day }}</a>
                                @endif
                            @endfor
                        </div>
                        <p class="slots-footnote">Elija un día para ver los intervalos y programar desde la vista diaria.</p>
                    </section>
                @else
                    <div class="week-scroll">
                        <div class="week-grid" style="--week-hour: {{ $hourHeight }}rem; --week-days: {{ max(1, count($days)) }}; --week-track: {{ $trackHeight }}rem; --week-hour-count: {{ count($hours) }};">
                            <div class="week-hours" aria-hidden="true">
                                <span class="week-corner"></span>
                                @foreach ($hours as $hour)
                                    <span class="week-hour">{{ $hour }}</span>
                                @endforeach
                            </div>
                            @foreach ($days as $day)
                                @php
                                    $iso = $day->toDateString();
                                    $isPast = $day->lt($today);
                                    $isToday = $day->isSameDay($today);
                                    $dayEvents = $eventsByDay->get($iso, collect());
                                @endphp
                                <section class="week-day @if ($isToday) is-today @endif @if ($isPast) is-past @endif" aria-label="{{ $weekdayNames[$day->isoWeekday()] ?? '' }} {{ $day->day }}">
                                    <header class="week-day-head">
                                        <span class="week-day-name-full">{{ $weekdayNames[$day->isoWeekday()] ?? $day->isoFormat('dddd') }}</span>
                                        <span class="week-day-name-short">{{ $shortWeekdays[$day->isoWeekday() - 1] ?? '' }}</span>
                                        <strong class="week-day-date-full">{{ $day->day }} de {{ $monthNames[(int) $day->month] }}</strong>
                                        <strong class="week-day-date-short">{{ $day->format('d/m') }}</strong>
                                    </header>
                                    <div class="week-track">
                                        @foreach ($hours as $hour)
                                            <div class="week-line"></div>
                                        @endforeach
                                        @if ($canBook && ! $isPast)
                                            @for ($hour = $boardStartHour; $hour < $boardEndHour; $hour++)
                                                @foreach ([0, 30] as $minute)
                                                    @php
                                                        $slotStart = sprintf('%02d:%02d', $hour, $minute);
                                                        $slotEnd = $minute === 0
                                                            ? sprintf('%02d:30', $hour)
                                                            : sprintf('%02d:00', $hour + 1);
                                                        $slotAt = $day->copy()->setTime($hour, $minute);
                                                        $isElapsed = $slotAt->lt($now);
                                                        $top = (($hour - $boardStartHour) + ($minute / 60)) * $hourHeight;
                                                    @endphp
                                                    @if (! $isElapsed)
                                                        <a class="week-slot" style="top: {{ $top }}rem; height: {{ $hourHeight / 2 }}rem;" href="{{ route('availability.consult', $slotQuery($iso, $slotStart, $slotEnd), false) }}">
                                                            <strong>Nuevo turno</strong>
                                                            <span>{{ $slotStart }} – {{ $slotEnd }}</span>
                                                        </a>
                                                    @endif
                                                @endforeach
                                            @endfor
                                        @endif
                                        @if ($canBook && ! $isPast && $bookingOpen && $fecha === $iso && $draftLayout)
                                            <div class="week-slot is-draft" style="top: {{ $draftLayout['top'] }}rem; height: {{ $draftLayout['height'] }}rem;">
                                                <strong>Nuevo turno</strong>
                                                <span>{{ $hora_inicio }} – {{ $hora_fin }}</span>
                                            </div>
                                        @endif
                                        @foreach ($dayEvents as $event)
                                            @php
                                                $isAppointment = $event['kind'] === 'cita';
                                                $href = $isAppointment && $canOpen && $event['id_cita']
                                                    ? route('appointments.show', $event['id_cita'], false)
                                                    : ($canBook && ! $isAppointment ? route('availability.consult', $slotQuery($event['fecha'], $event['hora_inicio'], $event['hora_fin']) + ['medico' => $event['id_medico']], false) : null);
                                                $title = $isAppointment ? $event['paciente'].' · '.$event['estado'] : 'Intervalo libre';
                                            @endphp
                                            @if ($href)
                                                <a class="week-event tone-{{ $event['tone'] }}" href="{{ $href }}" title="{{ $title }}" style="top: {{ $event['top'] }}rem; height: {{ $event['height'] }}rem;">
                                                    <span class="week-event-time">{{ $event['hora_inicio'] }} – {{ $event['hora_fin'] }}</span>
                                                    <strong>{{ $isAppointment ? $event['paciente'] : 'Libre' }}</strong>
                                                    <span>{{ $event['medico'] }}</span>
                                                </a>
                                            @else
                                                <div class="week-event tone-{{ $event['tone'] }}" title="{{ $title }}" style="top: {{ $event['top'] }}rem; height: {{ $event['height'] }}rem;">
                                                    <span class="week-event-time">{{ $event['hora_inicio'] }} – {{ $event['hora_fin'] }}</span>
                                                    <strong>{{ $isAppointment ? $event['paciente'] : 'Libre' }}</strong>
                                                    <span>{{ $event['medico'] }}</span>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </div>
                    @if ($events === [])
                        <p class="slots-footnote">No hay citas programadas en este periodo. Elija especialidad y un médico para ver también los intervalos libres, o pulse un hueco para programar.</p>
                    @elseif (! $especialidad || ! $medico)
                        <p class="slots-footnote">Elija especialidad y un médico para ver además los intervalos libres. Pulse un hueco para programar; verlo no lo reserva.</p>
                    @endif
                @endif

                <footer class="week-legend">
                    <p>
                        @forelse ($legend as $item)
                            <span class="week-legend-item"><span class="week-legend-dot tone-{{ $item['tone'] }}" aria-hidden="true"></span> {{ $item['medico'] }}</span>
                        @empty
                            <span class="week-legend-item"><span class="week-legend-dot tone-0" aria-hidden="true"></span> Citas y huecos libres</span>
                        @endforelse
                    </p>
                    <p>Seleccionar un intervalo no lo reserva.</p>
                </footer>
            </div>

            @if ($bookingOpen)
                @include('appointments._booking-panel')
            @endif
        </div>
    </section>
@endsection
