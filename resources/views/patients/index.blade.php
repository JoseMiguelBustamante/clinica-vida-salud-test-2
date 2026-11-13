@extends('layouts.clinic')

@section('title', 'Pacientes — Clínica Vida Salud')

@php
    $shown = $patients->count();
    $canConsultHistory = in_array(\App\Modules\Identity\PermissionCatalog::CONSULT_HISTORY, $identityPermissions ?? [], true);
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">General / Pacientes</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Pacientes</h1>
                <p class="lead">Identificación y contacto. El CI es opcional y, si se indica, no puede repetirse. Desactivar no borra citas.</p>
            </div>
            <a class="button button-inline" href="{{ route('patients.create') }}">Registrar paciente</a>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <div class="list-card">
            <form class="filter-bar" method="get" action="{{ route('patients.index', absolute: false) }}">
                <div class="field">
                    <label for="q">Buscar por CI, nombres o apellidos</label>
                    <input id="q" name="q" type="search" value="{{ $q }}" placeholder="CI, nombres o apellidos">
                </div>
                <button class="button" type="submit">Buscar</button>
            </form>

            @if ($patients->isEmpty())
                <p class="stack-card-empty">No hay pacientes que coincidan con la búsqueda.</p>
            @else
                <ul class="stack-cards patient-cards">
                    @foreach ($patients as $patient)
                        <li class="stack-card">
                            <div class="stack-card-top">
                                <strong class="stack-card-title patient-name">{{ $patient->nombres }} {{ $patient->apellidos }}</strong>
                                <span class="status-pill {{ $patient->activo ? 'is-active' : 'is-inactive' }}">
                                    {{ $patient->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                            <div class="stack-card-meta">
                                <span>CI {{ $patient->ci ?: '—' }}</span>
                                <span>Tel. {{ $patient->telefono ?: '—' }}</span>
                            </div>
                            <nav class="row-actions" aria-label="Acciones de {{ $patient->nombres }} {{ $patient->apellidos }}">
                                <a class="row-action" href="{{ route('patients.show', $patient->id_paciente) }}">Ver</a>
                                <a class="row-action" href="{{ route('patients.edit', $patient->id_paciente) }}">Modificar</a>
                                @if ($canConsultHistory)
                                    <a class="row-action" href="{{ route('appointments.history', ['id_paciente' => $patient->id_paciente]) }}">Historial</a>
                                @endif
                            </nav>
                        </li>
                    @endforeach
                </ul>

                <div class="table-wrap stack-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>CI</th>
                                <th>Paciente</th>
                                <th>Teléfono</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($patients as $patient)
                                <tr>
                                    <td>{{ $patient->ci ?: '—' }}</td>
                                    <td>
                                        <strong class="patient-name">{{ $patient->nombres }} {{ $patient->apellidos }}</strong>
                                    </td>
                                    <td>{{ $patient->telefono ?: '—' }}</td>
                                    <td>
                                        <span class="status-pill {{ $patient->activo ? 'is-active' : 'is-inactive' }}">
                                            {{ $patient->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td>
                                        <nav class="row-actions" aria-label="Acciones de {{ $patient->nombres }} {{ $patient->apellidos }}">
                                            <a class="row-action" href="{{ route('patients.show', $patient->id_paciente) }}">Ver</a>
                                            <a class="row-action" href="{{ route('patients.edit', $patient->id_paciente) }}">Modificar</a>
                                            @if ($canConsultHistory)
                                                <a class="row-action" href="{{ route('appointments.history', ['id_paciente' => $patient->id_paciente]) }}">Historial</a>
                                            @endif
                                        </nav>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="list-meta">{{ $shown === 1 ? 'Mostrando 1 paciente' : 'Mostrando '.$shown.' pacientes' }}</p>
        </div>
    </section>
@endsection
