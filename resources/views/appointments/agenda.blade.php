@extends('layouts.clinic')

@section('title', 'Agenda — Clínica Vida Salud')

@php
    $rows = $appointments === null
        ? collect()
        : $appointments->map(static function (object $appointment) use ($canMarkAttended): array {
            $start = \Illuminate\Support\Carbon::parse((string) $appointment->inicio)->timezone('UTC');
            $end = \Illuminate\Support\Carbon::parse((string) $appointment->fin)->timezone('UTC');
            $statusClass = in_array($appointment->estado, ['Atendida', 'Confirmada'], true)
                ? 'is-active'
                : (in_array($appointment->estado, ['Cancelada', 'Inasistencia'], true) ? 'is-inactive' : '');

            return [
                'appointment' => $appointment,
                'timeLabel' => $start->format('H:i').' – '.$end->format('H:i').' UTC',
                'statusClass' => $statusClass,
                'canAttend' => $canMarkAttended && in_array($appointment->estado, ['Programada', 'Confirmada'], true),
            ];
        });
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Agenda</p>
        <h1 id="page-title">Agenda médica</h1>
        <p class="lead">Consulta por médico y fecha. No modifica citas. El médico solo ve su propia agenda.</p>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif
        @if ($status)
            <p class="form-status" role="status">{{ $status }}</p>
        @endif

        <form class="filter-bar" method="get" action="{{ route('appointments.agenda', absolute: false) }}">
            @if ($canSelectPhysician)
                <div class="field">
                    <label for="id_medico">Médico</label>
                    <select id="id_medico" name="id_medico">
                        <option value="">Seleccione</option>
                        @foreach ($physicians as $physician)
                            <option value="{{ $physician->id_medico }}" @selected((string) $physician_id === (string) $physician->id_medico)>
                                {{ $physician->apellidos }}, {{ $physician->nombres }} · {{ $physician->registro_profesional }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="field">
                <label for="fecha">Fecha</label>
                <input id="fecha" name="fecha" type="date" value="{{ $fecha }}">
            </div>
            <div class="field">
                <label for="estado">Estado (opcional)</label>
                <select id="estado" name="estado">
                    <option value="">Todos</option>
                    @foreach (['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'] as $option)
                        <option value="{{ $option }}" @selected($estado === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <button class="button" type="submit">Consultar</button>
        </form>

        @if ($appointments === null)
            <p class="list-meta">Seleccione la fecha{{ $canSelectPhysician ? ' y el médico' : '' }} para ver la agenda.</p>
        @else
            <div class="list-card">
                @if ($rows->isEmpty())
                    <p class="agenda-card-empty">No hay citas registradas para esa consulta.</p>
                @else
                    <ul class="agenda-cards">
                        @foreach ($rows as $row)
                            <li class="agenda-card">
                                <div class="agenda-card-top">
                                    <strong class="agenda-card-time">{{ $row['timeLabel'] }}</strong>
                                    <span class="status-pill {{ $row['statusClass'] }}">{{ $row['appointment']->estado }}</span>
                                </div>
                                <p class="agenda-card-patient">{{ $row['appointment']->paciente_apellidos }}, {{ $row['appointment']->paciente_nombres }}</p>
                                <div class="agenda-card-meta">
                                    <span>{{ $row['appointment']->codigo }}</span>
                                    <span>{{ $row['appointment']->especialidad_nombre }}</span>
                                    @if ($canViewReason)
                                        <span>{{ $row['appointment']->motivo_consulta ?: 'Sin motivo' }}</span>
                                    @endif
                                </div>
                                <nav class="row-actions" aria-label="Acciones de {{ $row['appointment']->codigo }}">
                                    <a class="row-action" href="{{ route('appointments.show', $row['appointment']->id_cita) }}">Ver</a>
                                    @if ($row['canAttend'])
                                        <a class="row-action" href="{{ route('appointments.attend', $row['appointment']->id_cita) }}">Atender</a>
                                    @endif
                                </nav>
                            </li>
                        @endforeach
                    </ul>

                    <div class="table-wrap agenda-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Horario</th>
                                    <th>Código</th>
                                    <th>Paciente</th>
                                    <th>Especialidad</th>
                                    <th>Estado</th>
                                    @if ($canViewReason)
                                        <th>Motivo</th>
                                    @endif
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td>{{ $row['timeLabel'] }}</td>
                                        <td>{{ $row['appointment']->codigo }}</td>
                                        <td>{{ $row['appointment']->paciente_apellidos }}, {{ $row['appointment']->paciente_nombres }}</td>
                                        <td>{{ $row['appointment']->especialidad_nombre }}</td>
                                        <td>
                                            <span class="status-pill {{ $row['statusClass'] }}">{{ $row['appointment']->estado }}</span>
                                        </td>
                                        @if ($canViewReason)
                                            <td>{{ $row['appointment']->motivo_consulta ?: '—' }}</td>
                                        @endif
                                        <td>
                                            <nav class="row-actions" aria-label="Acciones de {{ $row['appointment']->codigo }}">
                                                <a class="row-action" href="{{ route('appointments.show', $row['appointment']->id_cita) }}">Ver</a>
                                                @if ($row['canAttend'])
                                                    <a class="row-action" href="{{ route('appointments.attend', $row['appointment']->id_cita) }}">Atender</a>
                                                @endif
                                            </nav>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="list-meta">{{ $rows->count() === 1 ? 'Mostrando 1 cita' : 'Mostrando '.$rows->count().' citas' }}</p>
            </div>
        @endif
    </section>
@endsection
