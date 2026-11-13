@extends('layouts.clinic')

@section('title', 'Médicos — Clínica Vida Salud')

@php
    $shown = $physicians->count();
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Médicos</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Médicos</h1>
                <p class="lead">Registro profesional único y al menos una especialidad. Un médico inactivo no recibe nuevas citas.</p>
            </div>
            <a class="button button-inline" href="{{ route('physicians.create') }}">Registrar médico</a>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <div class="list-card">
            <form class="filter-bar" method="get" action="{{ route('physicians.index', absolute: false) }}">
                <div class="field">
                    <label for="q">Buscar por nombre, registro o especialidad</label>
                    <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Nombre, registro o especialidad">
                </div>
                <button class="button" type="submit">Buscar</button>
            </form>

            @if ($physicians->isEmpty())
                <p class="stack-card-empty">No hay médicos que coincidan con la búsqueda.</p>
            @else
                <ul class="stack-cards physician-cards">
                    @foreach ($physicians as $physician)
                        <li class="stack-card">
                            <div class="stack-card-top">
                                <strong class="stack-card-title person-name">{{ $physician->nombres }} {{ $physician->apellidos }}</strong>
                                <span class="status-pill {{ $physician->activo ? 'is-active' : 'is-inactive' }}">
                                    {{ $physician->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                            <div class="stack-card-meta">
                                <span>{{ $physician->registro_profesional }}</span>
                                <span>{{ $physician->especialidades->pluck('nombre')->join(', ') ?: 'Sin especialidades' }}</span>
                            </div>
                            <nav class="row-actions" aria-label="Acciones de {{ $physician->nombres }} {{ $physician->apellidos }}">
                                <a class="row-action" href="{{ route('physicians.show', $physician->id_medico) }}">Ver</a>
                                <a class="row-action" href="{{ route('physicians.edit', $physician->id_medico) }}">Modificar</a>
                            </nav>
                        </li>
                    @endforeach
                </ul>

                <div class="table-wrap stack-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Registro</th>
                                <th>Médico</th>
                                <th>Especialidades</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($physicians as $physician)
                                <tr>
                                    <td>{{ $physician->registro_profesional }}</td>
                                    <td>
                                        <strong class="person-name">{{ $physician->nombres }} {{ $physician->apellidos }}</strong>
                                    </td>
                                    <td>{{ $physician->especialidades->pluck('nombre')->join(', ') ?: '—' }}</td>
                                    <td>
                                        <span class="status-pill {{ $physician->activo ? 'is-active' : 'is-inactive' }}">
                                            {{ $physician->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td>
                                        <nav class="row-actions" aria-label="Acciones de {{ $physician->nombres }} {{ $physician->apellidos }}">
                                            <a class="row-action" href="{{ route('physicians.show', $physician->id_medico) }}">Ver</a>
                                            <a class="row-action" href="{{ route('physicians.edit', $physician->id_medico) }}">Modificar</a>
                                        </nav>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="list-meta">{{ $shown === 1 ? 'Mostrando 1 médico' : 'Mostrando '.$shown.' médicos' }}</p>
        </div>
    </section>
@endsection
