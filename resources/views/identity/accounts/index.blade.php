@extends('layouts.clinic')

@section('title', 'Usuarios y roles — Clínica Vida Salud')

@php
    $shown = $accounts->count();
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Administración / Usuarios</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Usuarios y roles</h1>
                <p class="lead">Cuentas de acceso del personal. Una cuenta inactiva no puede iniciar sesión.</p>
            </div>
            <a class="button button-inline" href="{{ route('accounts.create') }}">Registrar usuario</a>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <div class="list-card">
            <form class="filter-bar" method="get" action="{{ route('accounts.index', absolute: false) }}">
                <div class="field">
                    <label for="q">Buscar por usuario o correo</label>
                    <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Usuario o correo">
                </div>
                <button class="button" type="submit">Buscar</button>
            </form>

            @if ($accounts->isEmpty())
                <p class="stack-card-empty">No hay cuentas que coincidan con la búsqueda.</p>
            @else
                <ul class="stack-cards account-cards">
                    @foreach ($accounts as $account)
                        <li class="stack-card">
                            <div class="stack-card-top">
                                <strong class="stack-card-title person-name">{{ $account->nombre_usuario }}</strong>
                                <span class="status-pill {{ $account->activo ? 'is-active' : 'is-inactive' }}">
                                    {{ $account->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                            <div class="stack-card-meta">
                                <span>{{ $account->correo_acceso ?: 'Sin correo' }}</span>
                                <span>{{ $account->rol_nombre }}</span>
                            </div>
                            <nav class="row-actions" aria-label="Acciones de {{ $account->nombre_usuario }}">
                                <a class="row-action" href="{{ route('accounts.show', $account->id_usuario) }}">Ver</a>
                                <a class="row-action" href="{{ route('accounts.edit', $account->id_usuario) }}">Modificar</a>
                            </nav>
                        </li>
                    @endforeach
                </ul>

                <div class="table-wrap stack-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                <tr>
                                    <td><strong class="person-name">{{ $account->nombre_usuario }}</strong></td>
                                    <td>{{ $account->correo_acceso ?: '—' }}</td>
                                    <td>{{ $account->rol_nombre }}</td>
                                    <td>
                                        <span class="status-pill {{ $account->activo ? 'is-active' : 'is-inactive' }}">
                                            {{ $account->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td>
                                        <nav class="row-actions" aria-label="Acciones de {{ $account->nombre_usuario }}">
                                            <a class="row-action" href="{{ route('accounts.show', $account->id_usuario) }}">Ver</a>
                                            <a class="row-action" href="{{ route('accounts.edit', $account->id_usuario) }}">Modificar</a>
                                        </nav>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="list-meta">{{ $shown === 1 ? 'Mostrando 1 cuenta' : 'Mostrando '.$shown.' cuentas' }}</p>
        </div>
    </section>
@endsection
