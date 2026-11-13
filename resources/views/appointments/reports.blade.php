@extends('layouts.clinic')

@section('title', 'Reportes administrativos — Clínica Vida Salud')

@section('content')
    <section class="app-page reports-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Administración / Reportes</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Reportes administrativos</h1>
                <p class="lead">Pida el reporte al asistente. No modifica registros ni muestra diagnósticos o notas médicas.</p>
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
            $needsRange = in_array($selectedType, ['citas_medico', 'citas_especialidad', 'citas_estado', 'totales_periodo'], true);
            $needsSingleDate = in_array($selectedType, ['citas_fecha', 'agenda_diaria'], true);
            $recordCount = $generated
                ? (int) ($type === 'totales_periodo'
                    ? (($totals['Atendida'] ?? 0) + ($totals['Cancelada'] ?? 0) + ($totals['Inasistencia'] ?? 0))
                    : ($rows?->count() ?? 0))
                : 0;
            $baseQuery = array_filter([
                'tipo' => $selectedType,
                'id_medico' => $selectedMedico,
                'id_especialidad' => $selectedEspecialidad,
                'estado' => $selectedEstado,
                'generar' => '1',
            ], fn ($value) => $value !== null && $value !== '');
        @endphp

        <article class="list-card detail-card reports-ai-card" id="asistente-reporte" aria-labelledby="assistant-title">
            <h2 id="assistant-title">Asistente del reporte</h2>
            <p class="reports-filter-hint" id="assistant-help">Hable con el asistente: diga qué reporte necesita y el período (hoy, esta semana, este mes o una fecha). Él responde por voz. También puede escribir.</p>
            @if (! ($aiConfigured ?? false))
                <p class="form-status" role="status">El asistente no está configurado. En Dokploy defina <code>OPENAI_API_KEY</code>, <code>OPENAI_BASE_URL</code> y <code>OPENAI_MODEL</code> del proveedor que use (API compatible; la suscripción del chat no alcanza).</p>
            @endif
            @if (! empty($aiClarifications))
                <div class="form-status" role="status" id="reports-clarifications">
                    <p>Para continuar, complete:</p>
                    <ul>
                        @foreach ($aiClarifications as $clarification)
                            <li>{{ $clarification }}</li>
                        @endforeach
                    </ul>
                </div>
                <p class="visually-hidden" id="reports-clarify-plain">Para continuar: {{ implode('. ', $aiClarifications) }}</p>
            @endif
            <form class="reports-ai-form" method="post" action="{{ route('reports.assistant', absolute: false) }}" id="reports-ai-form" data-voice-mode="{{ $generated ? 'preguntar' : 'resumir' }}">
                @csrf
                @foreach ($query as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <div class="reports-ai-voice reports-ai-voice-panel" data-reports-voice hidden>
                    <button class="reports-listen-btn reports-listen-btn-primary" type="button" id="reports-dictate" @disabled(! ($aiConfigured ?? false)) aria-pressed="false" aria-label="Hablar con el asistente">
                        <span class="reports-listen-pulse" aria-hidden="true"></span>
                        <span class="reports-listen-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.9V21h2v-3.1A7 7 0 0 0 19 11h-2Z"/>
                            </svg>
                        </span>
                        <span class="reports-listen-copy">
                            <span class="reports-listen-label">Hablar con el asistente</span>
                            <span class="reports-listen-caption">Toque, hable y espere la respuesta</span>
                        </span>
                    </button>
                    <p class="reports-filter-hint" id="reports-dictate-status" role="status" aria-live="polite">Toque el micrófono, diga el reporte y el período (hoy, esta semana, este mes…) y el asistente continuará solo.</p>
                </div>
                <details class="reports-ai-text-fallback">
                    <summary>Escribir en texto</summary>
                    <div class="field">
                        <label for="pregunta">Instrucción o pregunta</label>
                        <textarea id="pregunta" name="pregunta" rows="3" maxlength="500" aria-describedby="assistant-help reports-dictate-status" placeholder="Ejemplo: Agenda diaria de cada médico para el 2026-11-12" @disabled(! ($aiConfigured ?? false))>{{ old('pregunta', $aiQuestion && $aiQuestion !== 'Resumen del reporte' ? $aiQuestion : '') }}</textarea>
                    </div>
                    <div class="reports-ai-actions">
                        <button class="button" type="submit" name="accion" value="resumir" id="reports-action-resumir" @disabled(! ($aiConfigured ?? false))>Generar reporte</button>
                        @if ($generated)
                            <button class="button button-inline button-quiet" type="submit" name="accion" value="preguntar" id="reports-action-preguntar" @disabled(! ($aiConfigured ?? false))>Preguntar</button>
                        @endif
                    </div>
                    @unless ($generated)
                        <p class="reports-filter-hint" id="assistant-ask-hint">Preguntar se habilita cuando el reporte ya se generó sin error.</p>
                    @endunless
                </details>
            </form>
        </article>

        <details class="list-card detail-card reports-filter-card" @if ($selectedType !== '' && empty($aiAnswer) && empty($aiClarifications)) open @endif>
            <summary class="reports-filter-summary">
                <span class="reports-filter-summary-title">Filtros manuales</span>
                <span class="reports-filter-summary-hint">Opcional, si prefiere elegir tipo y fechas</span>
            </summary>
            <form class="filter-bar reports-filter-bar" method="get" action="{{ route('reports.generate', absolute: false) }}" id="reports-filter-form">
                <div class="field reports-field-type">
                    <label for="tipo">Tipo de reporte</label>
                    <select id="tipo" name="tipo" onchange="this.form.submit()">
                        <option value="">Seleccione</option>
                        @foreach ($types as $code => $label)
                            <option value="{{ $code }}" @selected($selectedType === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($needsSingleDate)
                    <div class="field">
                        <label for="fecha">Fecha</label>
                        <input id="fecha" name="fecha" type="date" value="{{ $selectedFecha }}" max="{{ $presetToday }}">
                    </div>
                @endif
                @if ($needsRange)
                    <div class="field">
                        <label for="fecha_inicio">Fecha inicial</label>
                        <input id="fecha_inicio" name="fecha_inicio" type="date" value="{{ $selectedFechaInicio }}" max="{{ $presetToday }}">
                    </div>
                    <div class="field">
                        <label for="fecha_fin">Fecha final</label>
                        <input id="fecha_fin" name="fecha_fin" type="date" value="{{ $selectedFechaFin }}" max="{{ $presetToday }}">
                    </div>
                @endif
                @if ($selectedType === 'citas_medico')
                    <div class="field">
                        <label for="id_medico">Médico</label>
                        <select id="id_medico" name="id_medico" onchange="this.form.requestSubmit()">
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
                        <select id="id_especialidad" name="id_especialidad" onchange="this.form.requestSubmit()">
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
                        <select id="estado" name="estado" onchange="this.form.requestSubmit()">
                            <option value="">Seleccione</option>
                            @foreach (['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'] as $option)
                                <option value="{{ $option }}" @selected($selectedEstado === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($needsRange || $needsSingleDate)
                    <div class="reports-date-presets" role="group" aria-label="Atajos de fecha" data-reports-date-presets>
                        @if ($needsSingleDate)
                            <button class="button button-inline button-quiet" type="button" data-reports-preset="hoy" data-fecha="{{ $presetToday }}">Hoy</button>
                        @else
                            <button class="button button-inline button-quiet" type="button" data-reports-preset="hoy" data-fecha-inicio="{{ $presetToday }}" data-fecha-fin="{{ $presetToday }}">Hoy</button>
                            <button class="button button-inline button-quiet" type="button" data-reports-preset="semana" data-fecha-inicio="{{ $presetWeekStart }}" data-fecha-fin="{{ $presetToday }}">Esta semana</button>
                            <button class="button button-inline button-quiet" type="button" data-reports-preset="mes" data-fecha-inicio="{{ $presetMonthStart }}" data-fecha-fin="{{ $presetToday }}">Este mes</button>
                        @endif
                    </div>
                @endif

                <div class="reports-filter-actions">
                    <button class="button button-inline" type="submit" name="generar" value="1">Generar con filtros</button>
                </div>
            </form>
        </details>

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

            <article class="list-card reports-result-card" id="resultado-reporte">
                <div class="reports-result-toolbar">
                    <div>
                        <h2>Resultado del reporte</h2>
                        <p class="list-meta">{{ $recordCount }} {{ $recordCount === 1 ? 'registro' : 'registros' }}</p>
                    </div>
                    <details class="reports-export">
                        <summary class="button button-inline">Exportar</summary>
                        <div class="reports-export-menu" role="menu">
                            <a role="menuitem" href="{{ route('reports.export', array_merge($query, ['formato' => 'pdf'])) }}" target="_blank" rel="noopener">PDF</a>
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

                <p class="list-meta reports-result-footer">Mostrando {{ $recordCount }} de {{ $recordCount }} {{ $recordCount === 1 ? 'registro' : 'registros' }} · PDF abre una vista lista para imprimir o guardar.</p>
            </article>

            @if (! empty($aiAnswer))
                <article class="list-card detail-card reports-ai-answer-card" aria-label="Respuesta del asistente">
                    <div class="reports-ai-answer-head">
                        <h2>Respuesta del asistente</h2>
                        <button class="reports-speak-btn" type="button" id="reports-speak-answer" data-reports-speak hidden aria-pressed="false" aria-label="Escuchar la respuesta del asistente">
                            <span class="reports-listen-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false">
                                    <path fill="currentColor" d="M3 10v4h4l5 5V5L7 10H3Zm13.5 2a4.5 4.5 0 0 0-2.5-4V16a4.5 4.5 0 0 0 2.5-4Zm-2.5-7.8v2.1a6.5 6.5 0 0 1 0 11.4v2.1a8.5 8.5 0 0 0 0-15.6Z"/>
                                </svg>
                            </span>
                            <span class="reports-speak-label">Escuchar respuesta</span>
                        </button>
                    </div>
                    @if ($aiQuestion && $aiQuestion !== 'Resumen del reporte')
                        <p class="reports-kpi-label">{{ $aiQuestion }}</p>
                    @endif
                    <div class="reports-ai-answer-body">{!! \Illuminate\Support\Str::markdown($aiAnswer) !!}</div>
                    <p class="visually-hidden" id="reports-answer-plain">{{ \App\Modules\Appointments\ReportSpeechText::plain($aiAnswer) }}</p>
                </article>
            @endif

        @elseif ($selectedType === '')
            <p class="list-meta">Escriba arriba qué reporte necesita, o abra filtros manuales si prefiere elegir el tipo.</p>
        @else
            <p class="list-meta">Complete el filtro pendiente (médico, especialidad o estado) o pídalo al asistente.</p>
        @endif
    </section>
    <script>
        (function () {
            var filterForm = document.getElementById('reports-filter-form');
            var presetGroup = document.querySelector('[data-reports-date-presets]');
            if (filterForm && presetGroup) {
                presetGroup.addEventListener('click', function (event) {
                    var target = event.target.closest('[data-reports-preset]');
                    if (! target || ! presetGroup.contains(target)) {
                        return;
                    }
                    event.preventDefault();
                    var fecha = filterForm.querySelector('#fecha');
                    var fechaInicio = filterForm.querySelector('#fecha_inicio');
                    var fechaFin = filterForm.querySelector('#fecha_fin');
                    if (fecha && target.hasAttribute('data-fecha')) {
                        fecha.value = target.getAttribute('data-fecha') || '';
                    }
                    if (fechaInicio && target.hasAttribute('data-fecha-inicio')) {
                        fechaInicio.value = target.getAttribute('data-fecha-inicio') || '';
                    }
                    if (fechaFin && target.hasAttribute('data-fecha-fin')) {
                        fechaFin.value = target.getAttribute('data-fecha-fin') || '';
                    }
                });
            }

            var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            var voiceBox = document.querySelector('[data-reports-voice]');
            var form = document.getElementById('reports-ai-form');
            var dictateBtn = document.getElementById('reports-dictate');
            var dictateLabel = dictateBtn ? dictateBtn.querySelector('.reports-listen-label') : null;
            var dictateStatus = document.getElementById('reports-dictate-status');
            var pregunta = document.getElementById('pregunta');
            var speakBtn = document.getElementById('reports-speak-answer');
            var speakLabel = speakBtn ? speakBtn.querySelector('.reports-speak-label') : null;
            var answerPlain = document.getElementById('reports-answer-plain');
            var clarifyPlain = document.getElementById('reports-clarify-plain');
            var recognition = null;
            var listening = false;
            var starting = false;
            var submitting = false;
            var speaking = false;
            var finalTranscript = '';
            var secure = window.isSecureContext === true;
            var voiceKey = 'reportsVoiceInteractive';
            var httpsHint = 'La conversación por voz en Chrome del celular requiere HTTPS. Abrí https://' + location.host + location.pathname + location.search;

            function setDictateStatus(message) {
                if (dictateStatus) {
                    dictateStatus.textContent = message || '';
                }
            }

            function setListenUi(active) {
                if (! dictateBtn || ! dictateLabel) {
                    return;
                }
                dictateBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
                dictateBtn.classList.toggle('is-listening', active);
                dictateLabel.textContent = active ? 'Escuchando…' : 'Hablar con el asistente';
                dictateBtn.setAttribute('aria-label', active ? 'Detener conversación' : 'Hablar con el asistente');
            }

            function setSpeakUi(active) {
                if (! speakBtn) {
                    return;
                }
                speakBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
                speakBtn.classList.toggle('is-speaking', active);
                if (speakLabel) {
                    speakLabel.textContent = active ? 'Detener audio' : 'Escuchar respuesta';
                }
            }

            function speakableText(text) {
                var plain = String(text || '');
                plain = plain.replace(/```[\s\S]*?```/g, ' ');
                plain = plain.replace(/`([^`]+)`/g, '$1');
                plain = plain.replace(/\*\*([^*]+)\*\*/g, '$1');
                plain = plain.replace(/\*([^*]+)\*/g, '$1');
                plain = plain.replace(/__([^_]+)__/g, '$1');
                plain = plain.replace(/_([^_]+)_/g, '$1');
                plain = plain.replace(/^#{1,6}\s+/gm, '');
                plain = plain.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
                try {
                    plain = plain.replace(/\p{Extended_Pictographic}/gu, '');
                    plain = plain.replace(/\p{Emoji_Component}/gu, '');
                } catch (e) {
                    plain = plain.replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '');
                    plain = plain.replace(/[\u2600-\u27BF]/g, '');
                }
                plain = plain.replace(/[*#_~>`|]/g, ' ');
                return plain.replace(/\s+/g, ' ').trim();
            }

            function speakText(text, onDone) {
                var spoken = speakableText(text);
                if (! ('speechSynthesis' in window) || ! spoken) {
                    if (onDone) {
                        onDone();
                    }
                    return;
                }
                window.speechSynthesis.cancel();
                var utterance = new SpeechSynthesisUtterance(spoken);
                utterance.lang = 'es-ES';
                utterance.onend = function () {
                    speaking = false;
                    setSpeakUi(false);
                    if (onDone) {
                        onDone();
                    }
                };
                utterance.onerror = function () {
                    speaking = false;
                    setSpeakUi(false);
                    if (onDone) {
                        onDone();
                    }
                };
                speaking = true;
                setSpeakUi(true);
                window.speechSynthesis.speak(utterance);
            }

            function composeSpokenValue(interim) {
                return (finalTranscript + (interim || '')).replace(/\s+/g, ' ').trim();
            }

            function submitVoiceTurn() {
                if (submitting || ! form || ! pregunta) {
                    return;
                }
                var spoken = finalTranscript.replace(/\s+/g, ' ').trim();
                if (spoken === '') {
                    setDictateStatus('No capturé la frase. Toque Hablar e intente de nuevo.');
                    return;
                }
                submitting = true;
                pregunta.value = spoken;
                try {
                    sessionStorage.setItem(voiceKey, '1');
                } catch (e) {}
                setDictateStatus('Enviando al asistente…');
                var mode = form.getAttribute('data-voice-mode') || 'resumir';
                var target = document.getElementById(mode === 'preguntar' ? 'reports-action-preguntar' : 'reports-action-resumir');
                if (! target || target.disabled) {
                    target = document.getElementById('reports-action-resumir');
                }
                if (! target || target.disabled) {
                    submitting = false;
                    setDictateStatus('No se pudo enviar. Revise la configuración del asistente.');
                    return;
                }
                target.click();
            }

            function bindRecognitionHandlers(instance) {
                instance.onstart = function () {
                    listening = true;
                    starting = false;
                    setListenUi(true);
                    setDictateStatus('Escuchando… diga el reporte o la pregunta.');
                };
                instance.onerror = function (event) {
                    starting = false;
                    stopListening(false);
                    if (event.error === 'not-allowed') {
                        setDictateStatus(secure
                            ? 'Chrome bloqueó el micrófono. En el candado del sitio, permití Micrófono y vuelva a hablar.'
                            : httpsHint);
                    } else if (event.error === 'service-not-allowed') {
                        setDictateStatus(secure
                            ? 'El servicio de voz no está disponible ahora. Intente de nuevo.'
                            : httpsHint);
                    } else if (event.error === 'network') {
                        setDictateStatus('Sin conexión al servicio de voz. Revise la red e intente otra vez.');
                    } else if (event.error === 'no-speech') {
                        setDictateStatus('No se escuchó audio. Toque Hablar e intente de nuevo.');
                    } else if (event.error !== 'aborted') {
                        setDictateStatus('No se pudo completar la conversación. Intente de nuevo.');
                    }
                };
                instance.onend = function () {
                    starting = false;
                    if (! listening) {
                        return;
                    }
                    var hadSpeech = finalTranscript.replace(/\s+/g, ' ').trim() !== '';
                    stopListening(true);
                    if (hadSpeech) {
                        submitVoiceTurn();
                    }
                };
                instance.onresult = function (event) {
                    var interim = '';
                    for (var i = event.resultIndex; i < event.results.length; i++) {
                        var chunk = event.results[i][0].transcript || '';
                        if (event.results[i].isFinal) {
                            finalTranscript += chunk;
                        } else {
                            interim += chunk;
                        }
                    }
                    pregunta.value = composeSpokenValue(interim);
                    if (event.results[event.results.length - 1].isFinal) {
                        setDictateStatus('Entendido. Enviando al asistente…');
                    }
                };
            }

            function createRecognition() {
                var instance = new Recognition();
                instance.lang = 'es-ES';
                instance.interimResults = true;
                instance.continuous = false;
                instance.maxAlternatives = 1;
                bindRecognitionHandlers(instance);
                return instance;
            }

            function stopListening(keepStatus) {
                listening = false;
                starting = false;
                setListenUi(false);
                if (! keepStatus) {
                    setDictateStatus('Toque el micrófono, diga el reporte y el período (hoy, esta semana, este mes…) y el asistente continuará solo.');
                }
                if (recognition) {
                    try { recognition.abort(); } catch (e) {}
                    try { recognition.stop(); } catch (e2) {}
                }
            }

            function unlockMicrophone() {
                if (! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) {
                    return Promise.resolve();
                }
                return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                    stream.getTracks().forEach(function (track) { track.stop(); });
                });
            }

            function startListening() {
                if (! secure) {
                    setDictateStatus(httpsHint);
                    return;
                }
                if (starting || listening || submitting) {
                    return;
                }
                if (speaking) {
                    window.speechSynthesis.cancel();
                    speaking = false;
                    setSpeakUi(false);
                }
                starting = true;
                listening = false;
                finalTranscript = '';
                if (pregunta) {
                    pregunta.value = '';
                }
                setDictateStatus('Preparando el micrófono…');
                if (recognition) {
                    try { recognition.abort(); } catch (e) {}
                    try { recognition.stop(); } catch (e2) {}
                }
                recognition = createRecognition();
                unlockMicrophone().then(function () {
                    try {
                        recognition.start();
                    } catch (e) {
                        starting = false;
                        setListenUi(false);
                        setDictateStatus('No se pudo iniciar la conversación. Toque Hablar otra vez.');
                    }
                }).catch(function () {
                    starting = false;
                    setListenUi(false);
                    setDictateStatus(secure
                        ? 'No se pudo usar el micrófono. Revise el permiso de Chrome para este sitio.'
                        : httpsHint);
                });
            }

            function resumeVoiceReply() {
                var active = false;
                try {
                    active = sessionStorage.getItem(voiceKey) === '1';
                    if (active) {
                        sessionStorage.removeItem(voiceKey);
                    }
                } catch (e) {
                    active = false;
                }
                if (! active) {
                    return;
                }
                var reply = '';
                if (clarifyPlain && clarifyPlain.textContent.trim() !== '') {
                    reply = clarifyPlain.textContent.trim();
                } else if (answerPlain && answerPlain.textContent.trim() !== '') {
                    reply = answerPlain.textContent.trim();
                } else if (document.getElementById('resultado-reporte')) {
                    reply = 'Listo. Ya generé el reporte en pantalla. Toque Hablar para preguntarme algo.';
                } else {
                    reply = 'Listo. Toque Hablar para continuar con el asistente.';
                }
                setDictateStatus('Respondiendo…');
                speakText(reply, function () {
                    setDictateStatus('Toque Hablar para continuar la conversación.');
                });
            }

            if (Recognition && voiceBox && dictateBtn && pregunta && ! pregunta.disabled) {
                voiceBox.hidden = false;
                recognition = createRecognition();
                if (! secure) {
                    setDictateStatus(httpsHint);
                }
                dictateBtn.addEventListener('click', function () {
                    if (listening || starting) {
                        stopListening(false);
                        return;
                    }
                    startListening();
                });
            }

            if (speakBtn && answerPlain && 'speechSynthesis' in window) {
                speakBtn.hidden = false;
                speakBtn.addEventListener('click', function () {
                    if (speaking) {
                        window.speechSynthesis.cancel();
                        speaking = false;
                        setSpeakUi(false);
                        return;
                    }
                    var text = (answerPlain.textContent || '').trim();
                    if (text === '') {
                        return;
                    }
                    speakText(text);
                });
            }

            resumeVoiceReply();
        })();
    </script>
@endsection
