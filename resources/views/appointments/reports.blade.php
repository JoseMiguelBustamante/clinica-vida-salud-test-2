@extends('layouts.clinic')

@section('title', 'Reportes administrativos — Clínica Vida Salud')

@section('content')
    <section class="app-page reports-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Administración / Reportes</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Reportes administrativos</h1>
                <p class="lead">Consulta por fecha, médico, especialidad y estado. No modifica registros ni muestra diagnósticos o notas médicas.</p>
            </div>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif
        @if ($status)
            <p class="form-status" role="status">{{ $status }}</p>
        @endif

        @php
            $selectedType = old('tipo', $type);
            $selectedFecha = old('fecha', $fecha);
            $selectedFechaInicio = old('fecha_inicio', $fecha_inicio);
            $selectedFechaFin = old('fecha_fin', $fecha_fin);
            $selectedMedico = old('id_medico', $id_medico);
            $selectedEspecialidad = old('id_especialidad', $id_especialidad);
            $selectedEstado = old('estado', $estado);
            $recordCount = $generated
                ? (int) ($type === 'totales_periodo'
                    ? (($totals['Atendida'] ?? 0) + ($totals['Cancelada'] ?? 0) + ($totals['Inasistencia'] ?? 0))
                    : ($rows?->count() ?? 0))
                : 0;
        @endphp

        <article class="list-card detail-card reports-filter-card">
            <h2>Generar reporte</h2>
            <form class="filter-bar reports-filter-bar" method="get" action="{{ route('reports.generate', absolute: false) }}" id="reports-filter-form">
                <div class="field">
                    <label for="tipo">Tipo de reporte</label>
                    <select id="tipo" name="tipo" onchange="this.form.submit()">
                        <option value="">Seleccione</option>
                        @foreach ($types as $code => $label)
                            <option value="{{ $code }}" @selected($selectedType === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (in_array($selectedType, ['citas_fecha', 'agenda_diaria'], true))
                    <div class="field">
                        <label for="fecha">Fecha</label>
                        <input id="fecha" name="fecha" type="date" value="{{ $selectedFecha }}">
                    </div>
                @endif
                @if (in_array($selectedType, ['citas_medico', 'citas_especialidad', 'citas_estado', 'totales_periodo'], true))
                    <div class="field">
                        <label for="fecha_inicio">Fecha inicial</label>
                        <input id="fecha_inicio" name="fecha_inicio" type="date" value="{{ $selectedFechaInicio }}">
                    </div>
                    <div class="field">
                        <label for="fecha_fin">Fecha final</label>
                        <input id="fecha_fin" name="fecha_fin" type="date" value="{{ $selectedFechaFin }}">
                    </div>
                @endif
                @if ($selectedType === 'citas_medico')
                    <div class="field">
                        <label for="id_medico">Médico</label>
                        <select id="id_medico" name="id_medico">
                            <option value="">Seleccione</option>
                            @foreach ($physicians as $physician)
                                <option value="{{ $physician->id_medico }}" @selected((string) $selectedMedico === (string) $physician->id_medico)>
                                    {{ $physician->apellidos }}, {{ $physician->nombres }} · {{ $physician->registro_profesional }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($selectedType === 'citas_especialidad')
                    <div class="field">
                        <label for="id_especialidad">Especialidad</label>
                        <select id="id_especialidad" name="id_especialidad">
                            <option value="">Seleccione</option>
                            @foreach ($specialties as $specialty)
                                <option value="{{ $specialty->id_especialidad }}" @selected((string) $selectedEspecialidad === (string) $specialty->id_especialidad)>
                                    {{ $specialty->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($selectedType === 'citas_estado')
                    <div class="field">
                        <label for="estado">Estado</label>
                        <select id="estado" name="estado">
                            <option value="">Seleccione</option>
                            @foreach (['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'] as $option)
                                <option value="{{ $option }}" @selected($selectedEstado === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button class="button" type="submit" name="generar" value="1">Generar reporte</button>
            </form>
        </article>

        @if ($generated)
            <section class="reports-kpi-grid" aria-label="Resumen del reporte">
                <article class="reports-kpi-card">
                    <p class="reports-kpi-label">Criterios aplicados</p>
                    <p class="reports-kpi-value reports-kpi-value-text">
                        {{ $types[$type] ?? $type }}
                        @if ($fecha !== '')
                            · Fecha {{ $fecha }}
                        @endif
                        @if ($fecha_inicio !== '' && $fecha_fin !== '')
                            · {{ $fecha_inicio }} a {{ $fecha_fin }}
                        @endif
                        @if ($estado !== '')
                            · {{ $estado }}
                        @endif
                    </p>
                </article>
                @if (isset($totals['citas']))
                    <article class="reports-kpi-card">
                        <p class="reports-kpi-label">Total de citas</p>
                        <p class="reports-kpi-value">{{ $totals['citas'] }}</p>
                    </article>
                    <article class="reports-kpi-card is-confirmada">
                        <p class="reports-kpi-label">Confirmadas</p>
                        <p class="reports-kpi-value">{{ $totals['Confirmada'] ?? 0 }}</p>
                    </article>
                    <article class="reports-kpi-card is-pendiente">
                        <p class="reports-kpi-label">Pendientes</p>
                        <p class="reports-kpi-value">{{ $totals['Pendientes'] ?? 0 }}</p>
                    </article>
                    <article class="reports-kpi-card is-cancelada">
                        <p class="reports-kpi-label">Canceladas</p>
                        <p class="reports-kpi-value">{{ $totals['Cancelada'] ?? 0 }}</p>
                    </article>
                @endif
                @if (isset($totals['pacientes']))
                    <article class="reports-kpi-card">
                        <p class="reports-kpi-label">Pacientes</p>
                        <p class="reports-kpi-value">{{ $totals['pacientes'] }}</p>
                    </article>
                    <article class="reports-kpi-card is-confirmada">
                        <p class="reports-kpi-label">Activos</p>
                        <p class="reports-kpi-value">{{ $totals['activos'] }}</p>
                    </article>
                    <article class="reports-kpi-card is-cancelada">
                        <p class="reports-kpi-label">Inactivos</p>
                        <p class="reports-kpi-value">{{ $totals['inactivos'] }}</p>
                    </article>
                @endif
                @if (isset($totals['Atendida']) && ! isset($totals['citas']))
                    <article class="reports-kpi-card is-confirmada">
                        <p class="reports-kpi-label">Atendidas</p>
                        <p class="reports-kpi-value">{{ $totals['Atendida'] }}</p>
                    </article>
                    <article class="reports-kpi-card is-cancelada">
                        <p class="reports-kpi-label">Canceladas</p>
                        <p class="reports-kpi-value">{{ $totals['Cancelada'] }}</p>
                    </article>
                    <article class="reports-kpi-card is-pendiente">
                        <p class="reports-kpi-label">Inasistencias</p>
                        <p class="reports-kpi-value">{{ $totals['Inasistencia'] }}</p>
                    </article>
                @endif
            </section>

            <article class="list-card reports-result-card">
                <div class="reports-result-toolbar">
                    <div>
                        <h2>Resultado del reporte</h2>
                        <p class="list-meta">{{ $recordCount }} {{ $recordCount === 1 ? 'registro' : 'registros' }}</p>
                    </div>
                    <details class="reports-export">
                        <summary class="button button-inline">Exportar</summary>
                        <div class="reports-export-menu" role="menu">
                            <a role="menuitem" href="{{ route('reports.export', array_merge($query, ['formato' => 'pdf'])) }}">PDF</a>
                            <a role="menuitem" href="{{ route('reports.export', array_merge($query, ['formato' => 'csv'])) }}">CSV</a>
                            <a role="menuitem" href="{{ route('reports.export', array_merge($query, ['formato' => 'excel'])) }}">Excel</a>
                        </div>
                    </details>
                </div>

                @if ($type === 'agenda_diaria')
                    @forelse ($groups as $group)
                        <h3 class="reports-group-title">{{ $group->physician->apellidos }}, {{ $group->physician->nombres }}</h3>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Horario</th>
                                        <th>Código</th>
                                        <th>Paciente</th>
                                        <th>Especialidad</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($group->appointments as $appointment)
                                        <tr>
                                            <td>{{ \Illuminate\Support\Carbon::parse((string) $appointment->inicio)->timezone('UTC')->format('H:i') }}
                                                – {{ \Illuminate\Support\Carbon::parse((string) $appointment->fin)->timezone('UTC')->format('H:i') }} UTC</td>
                                            <td>{{ $appointment->codigo }}</td>
                                            <td>{{ $appointment->paciente_apellidos }}, {{ $appointment->paciente_nombres }}</td>
                                            <td>{{ $appointment->especialidad_nombre }}</td>
                                            <td><span class="status-pill {{ $appointment->estado === 'Confirmada' ? 'is-confirmada' : ($appointment->estado === 'Cancelada' ? 'is-failed' : ($appointment->estado === 'Programada' ? 'is-programada' : '')) }}">{{ $appointment->estado }}</span></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5">No hay citas registradas para esa consulta.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @empty
                        <p class="list-meta">No hay datos para la consulta.</p>
                    @endforelse
                @elseif ($type === 'pacientes')
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>CI</th>
                                    <th>Nombres</th>
                                    <th>Apellidos</th>
                                    <th>Teléfono</th>
                                    <th>Correo</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $patient)
                                    <tr>
                                        <td>{{ $patient->ci ?: '—' }}</td>
                                        <td>{{ $patient->nombres }}</td>
                                        <td>{{ $patient->apellidos }}</td>
                                        <td>{{ $patient->telefono ?: '—' }}</td>
                                        <td>{{ $patient->correo_contacto ?: '—' }}</td>
                                        <td>
                                            <span class="status-pill {{ $patient->activo ? 'is-active' : 'is-inactive' }}">
                                                {{ $patient->activo ? 'Activo' : 'Inactivo' }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6">No hay datos para la consulta.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @elseif ($type === 'totales_periodo')
                    @if (($totals['Atendida'] ?? 0) === 0 && ($totals['Cancelada'] ?? 0) === 0 && ($totals['Inasistencia'] ?? 0) === 0)
                        <p class="list-meta">No hay datos para la consulta.</p>
                    @else
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Estado</th>
                                        <th>Cantidad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>Atendida</td><td>{{ $totals['Atendida'] }}</td></tr>
                                    <tr><td>Cancelada</td><td>{{ $totals['Cancelada'] }}</td></tr>
                                    <tr><td>Inasistencia</td><td>{{ $totals['Inasistencia'] }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Horario</th>
                                    <th>Código</th>
                                    <th>Paciente</th>
                                    <th>Médico</th>
                                    <th>Especialidad</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $appointment)
                                    <tr>
                                        <td>{{ \Illuminate\Support\Carbon::parse((string) $appointment->inicio)->timezone('UTC')->format('Y-m-d') }}</td>
                                        <td>{{ \Illuminate\Support\Carbon::parse((string) $appointment->inicio)->timezone('UTC')->format('H:i') }}
                                            – {{ \Illuminate\Support\Carbon::parse((string) $appointment->fin)->timezone('UTC')->format('H:i') }} UTC</td>
                                        <td>{{ $appointment->codigo }}</td>
                                        <td>{{ $appointment->paciente_apellidos }}, {{ $appointment->paciente_nombres }}</td>
                                        <td>{{ $appointment->medico_apellidos }}, {{ $appointment->medico_nombres }}</td>
                                        <td>{{ $appointment->especialidad_nombre }}</td>
                                        <td><span class="status-pill {{ $appointment->estado === 'Confirmada' ? 'is-confirmada' : ($appointment->estado === 'Cancelada' ? 'is-failed' : ($appointment->estado === 'Programada' ? 'is-programada' : '')) }}">{{ $appointment->estado }}</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">No hay datos para la consulta.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="list-meta reports-result-footer">Mostrando {{ $recordCount }} de {{ $recordCount }} {{ $recordCount === 1 ? 'registro' : 'registros' }}</p>
            </article>
        @elseif ($selectedType === '')
            <p class="list-meta">Seleccione un tipo de reporte e indique los filtros correspondientes.</p>
        @else
            <p class="list-meta">Indique los filtros del reporte seleccionado y pulse Generar reporte.</p>
        @endif
    </section>
@endsection
