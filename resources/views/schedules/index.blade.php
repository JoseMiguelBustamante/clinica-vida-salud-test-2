@extends('layouts.clinic')

@section('title', 'Horarios de atención — Clínica Vida Salud')

@php
    $shown = $schedules->count();
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Horarios</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Horarios de atención</h1>
                <p class="lead">Día, franja y duración de citas por médico. Desactivar no borra citas existentes.</p>
            </div>
            <a class="button button-inline" href="{{ route('schedules.create') }}">Registrar horario</a>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <div class="list-card">
            <form class="filter-bar" method="get" action="{{ route('schedules.index', absolute: false) }}">
                <div class="field">
                    <label for="q">Buscar por médico, registro o día</label>
                    <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Médico, registro o día">
                </div>
                <button class="button" type="submit">Buscar</button>
            </form>

            @if ($schedules->isEmpty())
                <p class="stack-card-empty">No hay horarios que coincidan con la búsqueda.</p>
            @else
                <ul class="stack-cards schedule-cards">
                    @foreach ($schedules as $schedule)
                        @php
                            $dayLabel = \App\Modules\Schedules\ScheduleService::WEEKDAY_LABELS[$schedule->dia_semana] ?? $schedule->dia_semana;
                            $slotLabel = substr((string) $schedule->hora_inicio, 0, 5).'–'.substr((string) $schedule->hora_fin, 0, 5);
                        @endphp
                        <li class="stack-card">
                            <div class="stack-card-top">
                                <strong class="stack-card-title person-name">{{ $schedule->nombres }} {{ $schedule->apellidos }}</strong>
                                <span class="status-pill {{ $schedule->activo ? 'is-active' : 'is-inactive' }}">
                                    {{ $schedule->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                            <div class="stack-card-meta">
                                <span>{{ $dayLabel }} · {{ $slotLabel }}</span>
                                <span>{{ $schedule->duracion_cita_minutos }} min</span>
                            </div>
                            <nav class="row-actions" aria-label="Acciones del horario">
                                <a class="row-action" href="{{ route('schedules.show', $schedule->id_horario) }}">Ver</a>
                                <a class="row-action" href="{{ route('schedules.edit', $schedule->id_horario) }}">Modificar</a>
                            </nav>
                        </li>
                    @endforeach
                </ul>

                <div class="table-wrap stack-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Médico</th>
                                <th>Día</th>
                                <th>Franja</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($schedules as $schedule)
                                <tr>
                                    <td><strong class="person-name">{{ $schedule->nombres }} {{ $schedule->apellidos }}</strong></td>
                                    <td>{{ \App\Modules\Schedules\ScheduleService::WEEKDAY_LABELS[$schedule->dia_semana] ?? $schedule->dia_semana }}</td>
                                    <td>{{ substr((string) $schedule->hora_inicio, 0, 5) }}–{{ substr((string) $schedule->hora_fin, 0, 5) }}</td>
                                    <td>{{ $schedule->duracion_cita_minutos }} min</td>
                                    <td>
                                        <span class="status-pill {{ $schedule->activo ? 'is-active' : 'is-inactive' }}">
                                            {{ $schedule->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td>
                                        <nav class="row-actions" aria-label="Acciones del horario">
                                            <a class="row-action" href="{{ route('schedules.show', $schedule->id_horario) }}">Ver</a>
                                            <a class="row-action" href="{{ route('schedules.edit', $schedule->id_horario) }}">Modificar</a>
                                        </nav>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="list-meta">{{ $shown === 1 ? 'Mostrando 1 horario' : 'Mostrando '.$shown.' horarios' }}</p>
        </div>
    </section>
@endsection
