@extends('layouts.clinic')

@section('title', 'Especialidades — Clínica Vida Salud')

@php
    $shown = $specialties->count();
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Especialidades</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Especialidades</h1>
                <p class="lead">Catálogo clínico. El nombre se normaliza y no puede repetirse. Desactivar no borra médicos ni citas.</p>
            </div>
            <a class="button button-inline" href="{{ route('specialties.create') }}">Registrar especialidad</a>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <div class="list-card">
            <form class="filter-bar" method="get" action="{{ route('specialties.index', absolute: false) }}">
                <div class="field">
                    <label for="q">Buscar por nombre</label>
                    <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Nombre de la especialidad">
                </div>
                <button class="button" type="submit">Buscar</button>
            </form>

            @if ($specialties->isEmpty())
                <p class="stack-card-empty">No hay especialidades que coincidan con la búsqueda.</p>
            @else
                <ul class="stack-cards specialty-cards">
                    @foreach ($specialties as $specialty)
                        <li class="stack-card">
                            <div class="stack-card-top">
                                <strong class="stack-card-title person-name">{{ $specialty->nombre }}</strong>
                                <span class="status-pill {{ $specialty->activo ? 'is-active' : 'is-inactive' }}">
                                    {{ $specialty->activo ? 'Activa' : 'Inactiva' }}
                                </span>
                            </div>
                            <div class="stack-card-meta">
                                <span>{{ $specialty->descripcion ?: 'Sin descripción' }}</span>
                            </div>
                            <nav class="row-actions" aria-label="Acciones de {{ $specialty->nombre }}">
                                <a class="row-action" href="{{ route('specialties.show', $specialty->id_especialidad) }}">Ver</a>
                                <a class="row-action" href="{{ route('specialties.edit', $specialty->id_especialidad) }}">Modificar</a>
                            </nav>
                        </li>
                    @endforeach
                </ul>

                <div class="table-wrap stack-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($specialties as $specialty)
                                <tr>
                                    <td><strong class="person-name">{{ $specialty->nombre }}</strong></td>
                                    <td>{{ $specialty->descripcion ?: '—' }}</td>
                                    <td>
                                        <span class="status-pill {{ $specialty->activo ? 'is-active' : 'is-inactive' }}">
                                            {{ $specialty->activo ? 'Activa' : 'Inactiva' }}
                                        </span>
                                    </td>
                                    <td>
                                        <nav class="row-actions" aria-label="Acciones de {{ $specialty->nombre }}">
                                            <a class="row-action" href="{{ route('specialties.show', $specialty->id_especialidad) }}">Ver</a>
                                            <a class="row-action" href="{{ route('specialties.edit', $specialty->id_especialidad) }}">Modificar</a>
                                        </nav>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="list-meta">{{ $shown === 1 ? 'Mostrando 1 especialidad' : 'Mostrando '.$shown.' especialidades' }}</p>
        </div>
    </section>
@endsection
