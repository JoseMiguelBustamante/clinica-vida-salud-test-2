@extends('layouts.clinic')

@section('title', 'Bitácora — Clínica Vida Salud')

@php
    $shown = $entries->count();
    $months = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];
    $filters = array_filter([
        'q' => $q !== '' ? $q : null,
        'id_usuario' => $id_usuario !== '' ? $id_usuario : null,
        'accion' => $accion !== '' ? $accion : null,
        'desde' => $desde !== '' ? $desde : null,
        'hasta' => $hasta !== '' ? $hasta : null,
    ], static fn ($value): bool => $value !== null);
    $rows = $entries->map(static function (object $entry) use ($months): array {
        $at = \Illuminate\Support\Carbon::parse((string) $entry->fecha_hora, 'UTC');

        return [
            'entry' => $entry,
            'at' => $at,
            'dateLabel' => $at->day.' '.$months[(int) $at->month].' '.$at->year,
        ];
    });
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Administración / Bitácora</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Bitácora de actividad</h1>
                <p class="lead">Consulta los accesos y cambios realizados en el sistema.</p>
            </div>
            <p class="consult-badge">Solo consulta</p>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <div class="list-card">
            <form class="filter-bar activity-filters" method="get" action="{{ route('activity-log.index', absolute: false) }}">
                <div class="field">
                    <label for="q">Buscar por usuario o detalle</label>
                    <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Usuario, módulo o detalle…">
                </div>
                <div class="field">
                    <label for="id_usuario">Usuario</label>
                    <select id="id_usuario" name="id_usuario">
                        <option value="">Todos</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id_usuario }}" @selected($id_usuario === (string) $account->id_usuario)>
                                {{ $account->nombre_usuario }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="accion">Acción</label>
                    <select id="accion" name="accion">
                        <option value="">Todas</option>
                        @foreach ($actions as $option)
                            <option value="{{ $option }}" @selected($accion === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="desde">Desde</label>
                    <input id="desde" name="desde" type="date" value="{{ $desde }}">
                </div>
                <div class="field">
                    <label for="hasta">Hasta</label>
                    <input id="hasta" name="hasta" type="date" value="{{ $hasta }}">
                </div>
                <button class="button button-inline" type="submit">Filtrar</button>
                <a class="filter-clear" href="{{ route('activity-log.index', absolute: false) }}">Limpiar</a>
            </form>

            @if ($rows->isEmpty())
                <p class="activity-card-empty">No hay movimientos que coincidan con la búsqueda.</p>
            @else
                <ul class="activity-cards">
                    @foreach ($rows as $row)
                        <li>
                            <details class="activity-card">
                                <summary>
                                    <span class="activity-card-top">
                                        <span class="when-line">
                                            <span class="activity-caret" aria-hidden="true"></span>
                                            <strong>{{ $row['dateLabel'] }}</strong>
                                            <span>{{ $row['at']->format('H:i') }}</span>
                                        </span>
                                        <span class="status-pill {{ $row['entry']->statusClass }}">{{ $row['entry']->accion }}</span>
                                    </span>
                                    <span class="activity-card-actor">
                                        <strong>{{ $row['entry']->actor_nombre ?: '—' }}</strong>
                                        @if ($row['entry']->actor_rol)
                                            <span>{{ $row['entry']->actor_rol }}</span>
                                        @endif
                                    </span>
                                    <span class="activity-card-meta">
                                        <span>{{ $row['entry']->modulo }}</span>
                                        <span>{{ $row['entry']->detalle }}</span>
                                        <span>IP {{ $row['entry']->direccion_ip ?: '—' }}</span>
                                    </span>
                                    <span class="activity-open">Ver cambios</span>
                                </summary>
                                @include('identity.activity-log._diff', ['entry' => $row['entry']])
                            </details>
                        </li>
                    @endforeach
                </ul>

                <div class="table-wrap activity-table-wrap">
                    <table class="data-table activity-table">
                        <thead>
                            <tr>
                                <th>Fecha y hora</th>
                                <th>Usuario</th>
                                <th>Dirección IP</th>
                                <th>Acción</th>
                                <th>Módulo</th>
                                <th>Detalle</th>
                                <th>Cambios</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td colspan="7" class="activity-row">
                                        <details>
                                            <summary>
                                                <span class="when-line">
                                                    <span class="activity-caret" aria-hidden="true"></span>
                                                    <strong>{{ $row['dateLabel'] }}</strong>
                                                    <span>{{ $row['at']->format('H:i') }}</span>
                                                </span>
                                                <span class="staff-line">
                                                    <strong>{{ $row['entry']->actor_nombre ?: '—' }}</strong>
                                                    @if ($row['entry']->actor_rol)
                                                        <span class="staff-specialty">{{ $row['entry']->actor_rol }}</span>
                                                    @endif
                                                </span>
                                                <span>{{ $row['entry']->direccion_ip ?: '—' }}</span>
                                                <span><span class="status-pill {{ $row['entry']->statusClass }}">{{ $row['entry']->accion }}</span></span>
                                                <span>{{ $row['entry']->modulo }}</span>
                                                <span>{{ $row['entry']->detalle }}</span>
                                                <span class="activity-open">Ver cambios</span>
                                            </summary>
                                            @include('identity.activity-log._diff', ['entry' => $row['entry']])
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="list-footer">
                <p class="list-meta">{{ $shown === 1 ? 'Mostrando 1 movimiento' : 'Mostrando '.$shown.' de '.$total.' movimientos' }}</p>
                @if ($pages > 1)
                    <nav class="pager" aria-label="Páginas de la bitácora">
                        @if ($page > 1)
                            <a href="{{ route('activity-log.index', array_merge($filters, ['pagina' => $page - 1]), false) }}">‹</a>
                        @endif
                        <span aria-current="page">{{ $page }}</span>
                        @if ($page < $pages)
                            <a href="{{ route('activity-log.index', array_merge($filters, ['pagina' => $page + 1]), false) }}">›</a>
                        @endif
                    </nav>
                @endif
            </div>
        </div>
    </section>
@endsection
