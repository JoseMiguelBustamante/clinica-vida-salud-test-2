@extends('layouts.clinic')

@section('title', 'Historial de citas — Clínica Vida Salud')

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Historial</p>
        <h1 id="page-title">Citas e historial del paciente</h1>
        <p class="lead">Consulta por paciente y estado. No muestra diagnósticos ni notas médicas. El historial del portal es una consulta distinta.</p>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif
        @if ($status)
            <p class="form-status" role="status">{{ $status }}</p>
        @endif

        <form class="filter-bar" method="get" action="{{ route('appointments.history', absolute: false) }}">
            <div class="field">
                <label for="q">Buscar paciente por CI, nombres o apellidos</label>
                <input id="q" name="q" type="search" value="{{ $q }}" placeholder="CI, nombres o apellidos">
            </div>
            @if ($patient)
                <input type="hidden" name="id_paciente" value="{{ $patient->id_paciente }}">
            @endif
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

        @if ($matches->isNotEmpty())
            <div class="list-card">
                <ul class="stack-cards history-match-cards">
                    @foreach ($matches as $match)
                        <li class="stack-card">
                            <div class="stack-card-top">
                                <strong class="stack-card-title person-name">{{ $match->apellidos }}, {{ $match->nombres }}</strong>
                                <span class="status-pill {{ $match->activo ? 'is-active' : 'is-inactive' }}">
                                    {{ $match->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                            <div class="stack-card-meta">
                                <span>CI {{ $match->ci ?: '—' }}</span>
                            </div>
                            <nav class="row-actions" aria-label="Acciones de {{ $match->nombres }} {{ $match->apellidos }}">
                                <a class="row-action" href="{{ route('appointments.history', ['id_paciente' => $match->id_paciente, 'estado' => $estado]) }}">Ver historial</a>
                            </nav>
                        </li>
                    @endforeach
                </ul>

                <div class="table-wrap stack-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Paciente</th>
                                <th>CI</th>
                                <th>Estado ficha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($matches as $match)
                                <tr>
                                    <td><strong class="person-name">{{ $match->apellidos }}, {{ $match->nombres }}</strong></td>
                                    <td>{{ $match->ci ?: '—' }}</td>
                                    <td>
                                        <span class="status-pill {{ $match->activo ? 'is-active' : 'is-inactive' }}">
                                            {{ $match->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td>
                                        <nav class="row-actions">
                                            <a class="row-action" href="{{ route('appointments.history', ['id_paciente' => $match->id_paciente, 'estado' => $estado]) }}">Ver historial</a>
                                        </nav>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($q !== '')
            <p>No hay pacientes que coincidan con la búsqueda.</p>
        @endif

        @if ($patient)
            <article class="list-card detail-card">
                <dl class="detail-list">
                    <div>
                        <dt>Paciente</dt>
                        <dd>{{ $patient->apellidos }}, {{ $patient->nombres }}</dd>
                    </div>
                    <div>
                        <dt>CI</dt>
                        <dd>{{ $patient->ci ?: 'no indicado' }}</dd>
                    </div>
                    <div>
                        <dt>Estado de ficha</dt>
                        <dd>
                            <span class="status-pill {{ $patient->activo ? 'is-active' : 'is-inactive' }}">
                                {{ $patient->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </article>

            <div class="list-card">
                @if ($appointments->isEmpty())
                    <p class="stack-card-empty">No hay citas que coincidan con los criterios.</p>
                @else
                    <ul class="stack-cards history-appointment-cards">
                        @foreach ($appointments as $appointment)
                            @php
                                $start = \Illuminate\Support\Carbon::parse((string) $appointment->inicio)->timezone('UTC');
                                $end = \Illuminate\Support\Carbon::parse((string) $appointment->fin)->timezone('UTC');
                                $statusClass = in_array($appointment->estado, ['Atendida', 'Confirmada'], true)
                                    ? 'is-active'
                                    : (in_array($appointment->estado, ['Cancelada', 'Inasistencia'], true) ? 'is-inactive' : '');
                            @endphp
                            <li class="stack-card">
                                <div class="stack-card-top">
                                    <strong class="stack-card-title">{{ $appointment->codigo }}</strong>
                                    <span class="status-pill {{ $statusClass }}">{{ $appointment->estado }}</span>
                                </div>
                                <div class="stack-card-meta">
                                    <span>{{ $start->format('Y-m-d') }} · {{ $start->format('H:i') }} – {{ $end->format('H:i') }} UTC</span>
                                    <span>{{ $appointment->medico_apellidos }}, {{ $appointment->medico_nombres }}</span>
                                    <span>{{ $appointment->especialidad_nombre }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="table-wrap stack-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Horario</th>
                                    <th>Código</th>
                                    <th>Médico</th>
                                    <th>Especialidad</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($appointments as $appointment)
                                    @php
                                        $start = \Illuminate\Support\Carbon::parse((string) $appointment->inicio)->timezone('UTC');
                                        $end = \Illuminate\Support\Carbon::parse((string) $appointment->fin)->timezone('UTC');
                                        $statusClass = in_array($appointment->estado, ['Atendida', 'Confirmada'], true)
                                            ? 'is-active'
                                            : (in_array($appointment->estado, ['Cancelada', 'Inasistencia'], true) ? 'is-inactive' : '');
                                    @endphp
                                    <tr>
                                        <td>{{ $start->format('Y-m-d') }}</td>
                                        <td>{{ $start->format('H:i') }} – {{ $end->format('H:i') }} UTC</td>
                                        <td>{{ $appointment->codigo }}</td>
                                        <td>{{ $appointment->medico_apellidos }}, {{ $appointment->medico_nombres }}</td>
                                        <td>{{ $appointment->especialidad_nombre }}</td>
                                        <td>
                                            <span class="status-pill {{ $statusClass }}">{{ $appointment->estado }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="list-meta">{{ $appointments->count() === 1 ? 'Mostrando 1 cita' : 'Mostrando '.$appointments->count().' citas' }}</p>
            </div>
        @elseif ($q === '')
            <p class="list-meta">Busque y seleccione un paciente para ver su historial de citas.</p>
        @endif
    </section>
@endsection
