@extends('layouts.clinic')

@section('title', 'Cita '.$appointment->codigo.' — Clínica Vida Salud')

@php
    $P = \App\Modules\Identity\PermissionCatalog::class;
    $codes = $identityPermissions ?? [];
    $has = static fn (string $code): bool => in_array($code, $codes, true);
@endphp

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Citas</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">{{ $appointment->codigo }}</h1>
                <p class="lead">
                    <span class="status-pill {{ in_array($appointment->estado, ['Atendida', 'Confirmada'], true) ? 'is-active' : (in_array($appointment->estado, ['Cancelada', 'Inasistencia'], true) ? 'is-inactive' : '') }}">
                        {{ $appointment->estado }}
                    </span>
                    · {{ $appointment->modalidad }}
                </p>
            </div>
            <nav class="row-actions" aria-label="Acciones">
                @if ($has($P::RESCHEDULE_APPOINTMENTS) && in_array($appointment->estado, ['Programada', 'Confirmada'], true))
                    <a class="row-action" href="{{ route('appointments.reschedule', $appointment->id_cita) }}">Reprogramar</a>
                @endif
                @if ($has($P::CANCEL_APPOINTMENTS) && in_array($appointment->estado, ['Programada', 'Confirmada'], true))
                    <a class="row-action" href="{{ route('appointments.cancel', $appointment->id_cita) }}">Cancelar</a>
                @endif
                @if ($has($P::RECORD_ATTENDANCE))
                    <a class="row-action" href="{{ route('appointments.attendance', $appointment->id_cita) }}">Asistencia</a>
                @endif
                @if ($has($P::MARK_ATTENDED) && in_array($appointment->estado, ['Programada', 'Confirmada'], true))
                    <a class="row-action" href="{{ route('appointments.attend', $appointment->id_cita) }}">Atender</a>
                @endif
                @if ($has($P::RESCHEDULE_APPOINTMENTS))
                    <a class="row-action" href="{{ route('appointments.index') }}">Volver al listado</a>
                @endif
            </nav>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="form-errors" role="alert"><p>{{ $errors->first() }}</p></div>
        @endif

        <article class="list-card detail-card">
            <dl class="detail-list">
                <div>
                    <dt>Paciente</dt>
                    <dd>{{ $appointment->paciente_apellidos }}, {{ $appointment->paciente_nombres }}
                        · CI {{ $appointment->paciente_ci ?: 'no indicado' }}</dd>
                </div>
                <div>
                    <dt>Especialidad</dt>
                    <dd>{{ $appointment->especialidad_nombre }}</dd>
                </div>
                <div>
                    <dt>Médico</dt>
                    <dd>{{ $appointment->medico_apellidos }}, {{ $appointment->medico_nombres }}
                        · {{ $appointment->registro_profesional }}</dd>
                </div>
                <div>
                    <dt>Intervalo</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse((string) $appointment->inicio)->timezone('UTC')->format('Y-m-d H:i') }}
                        – {{ \Illuminate\Support\Carbon::parse((string) $appointment->fin)->timezone('UTC')->format('H:i') }} UTC</dd>
                </div>
                <div>
                    <dt>Llegada</dt>
                    <dd>{{ isset($appointment->fecha_hora_llegada) && $appointment->fecha_hora_llegada
                        ? \Illuminate\Support\Carbon::parse((string) $appointment->fecha_hora_llegada)->timezone('UTC')->format('Y-m-d H:i')
                        : 'no registrada' }}</dd>
                </div>
                @if (isset($appointment->fecha_hora_registro_atencion) && $appointment->fecha_hora_registro_atencion)
                    <div>
                        <dt>Atención</dt>
                        <dd>{{ \Illuminate\Support\Carbon::parse((string) $appointment->fecha_hora_registro_atencion)->timezone('UTC')->format('Y-m-d H:i') }} UTC</dd>
                    </div>
                @endif
                <div>
                    <dt>Motivo</dt>
                    <dd>{{ $appointment->motivo_consulta ?: '—' }}</dd>
                </div>
                @if ($appointment->estado === 'Cancelada')
                    <div>
                        <dt>Motivo de cancelación</dt>
                        <dd>{{ $appointment->motivo_cancelacion ?: '—' }}</dd>
                    </div>
                @endif
            </dl>
        </article>

        @if ($paymentOverview !== null)
            <article class="list-card detail-card" aria-labelledby="payment-title">
                <h2 id="payment-title">Pago de consulta</h2>
                <dl class="detail-list">
                    <div>
                        <dt>Tarifa</dt>
                        <dd>{{ $paymentOverview['amount'] }} {{ $paymentOverview['currency'] }}</dd>
                    </div>
                <div>
                    <dt>Estado del pago</dt>
                    <dd>
                        @if ($paymentOverview['payment'])
                            <span class="status-pill {{ $paymentOverview['payment']->estado === 'Confirmado' ? 'is-confirmada' : ($paymentOverview['payment']->estado === 'Pendiente' ? 'is-programada' : 'is-failed') }}">
                                {{ $paymentOverview['payment']->estado }}
                            </span>
                        @else
                            <span class="status-pill is-inactive">Sin cobro iniciado</span>
                        @endif
                    </dd>
                </div>
                </dl>

                @if ($paymentOverview['payment'] && $paymentOverview['payment']->estado === 'Confirmado')
                    <p class="form-status" role="status">
                        Pago recibido: {{ $paymentOverview['amount'] }} {{ $paymentOverview['currency'] }}.
                        La consulta quedó cobrada
                        @if ($appointment->estado === 'Confirmada')
                            y la cita está Confirmada.
                        @else
                            ; revise el estado de la cita.
                        @endif
                    </p>
                @endif

                @if (! empty($checkoutUrl) && ! ($paymentOverview['payment'] && $paymentOverview['payment']->estado === 'Confirmado'))
                    <div style="display:grid;gap:0.75rem;justify-items:start;margin:1rem 0">
                        <img
                            src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={{ rawurlencode($checkoutUrl) }}"
                            alt="Código QR para que el cliente pague la consulta"
                            width="220"
                            height="220"
                        >
                        <p class="list-meta">El cliente escanea este QR o abre el link en su celular. El personal no paga.</p>
                        <p><a class="row-action" href="{{ $checkoutUrl }}" target="_blank" rel="noopener noreferrer">Abrir link de pago</a></p>
                        <p class="list-meta" style="word-break:break-all">{{ $checkoutUrl }}</p>
                    </div>
                @elseif ($paymentOverview['payment'] && $paymentOverview['payment']->estado === 'Pendiente')
                    <p><a class="row-action" href="{{ route('appointments.payment.qr', $appointment->id_cita) }}">Ver QR de cobro</a></p>
                    <p class="list-meta">Hay un cobro Pendiente. Si el QR no aparece, generá uno nuevo abajo.</p>
                @endif
                @if ($paymentOverview['can_start'] && $paymentOverview['configured'])
                    <form method="post" action="{{ route('appointments.payment.stripe', $appointment->id_cita, false) }}" class="status-form">
                        @csrf
                        <button class="button button-inline" type="submit">Generar QR de cobro</button>
                    </form>
                    <p class="list-meta">Genera un QR/link Stripe (BOB) para que el cliente pague en su teléfono. Al confirmar el webhook, la cita pasa a Confirmada.</p>
                @elseif ($paymentOverview['can_start'] && ! $paymentOverview['configured'])
                    <p class="list-meta">El cobro en línea no está configurado en este entorno.</p>
                @elseif (! ($paymentOverview['payment'] && in_array($paymentOverview['payment']->estado, ['Confirmado', 'Pendiente'], true)) && empty($checkoutUrl))
                    <p class="list-meta">Esta cita no admite un nuevo cobro en línea.</p>
                @endif
            </article>
        @endif

        @if ($canManageReminders ?? false)
            <article class="list-card detail-card" aria-labelledby="reminder-title">
                <h2 id="reminder-title">Recordatorio por correo</h2>
                @if ($reminder)
                    <dl class="detail-list">
                        <div>
                            <dt>Estado</dt>
                            <dd>{{ $reminder['status_label'] }}</dd>
                        </div>
                        <div>
                            <dt>Detalle</dt>
                            <dd>{{ $reminder['detail'] }}</dd>
                        </div>
                        @if ($reminder['reminder'])
                            <div>
                                <dt>Programado</dt>
                                <dd>{{ \Illuminate\Support\Carbon::parse((string) $reminder['reminder']->fecha_programada)->timezone('UTC')->format('Y-m-d H:i') }} UTC</dd>
                            </div>
                            @if ($reminder['reminder']->fecha_intento)
                                <div>
                                    <dt>Último intento</dt>
                                    <dd>{{ \Illuminate\Support\Carbon::parse((string) $reminder['reminder']->fecha_intento)->timezone('UTC')->format('Y-m-d H:i') }} UTC</dd>
                                </div>
                            @endif
                            @if ($reminder['reminder']->referencia_envio)
                                <div>
                                    <dt>Referencia</dt>
                                    <dd>{{ $reminder['reminder']->referencia_envio }}</dd>
                                </div>
                            @endif
                        @endif
                    </dl>
                    @if ($reminder['can_send_now'])
                        <form method="post" action="{{ route('appointments.reminder.send', $appointment->id_cita, false) }}" class="status-form">
                            @csrf
                            <button class="button button-inline" type="submit">Enviar recordatorio ahora</button>
                        </form>
                        <p class="list-meta">Envía el aviso de inmediato (sin esperar las 24 h). Usa el correo verificado del portal si está listo; si no, el correo de contacto de la ficha. No confirma la cita.</p>
                    @elseif (in_array($appointment->estado, ['Programada', 'Confirmada'], true))
                        <p class="list-meta">No hay destino usable: complete Perfil del paciente (recordatorios + correo verificado) o cargue un correo de contacto en la ficha.</p>
                    @endif
                @else
                    <p class="list-meta">No se pudo consultar el estado del recordatorio.</p>
                @endif
            </article>
        @endif

        <p class="list-meta">Programar o reprogramar no confirma la asistencia. Cancelar libera el horario y conserva el registro.</p>
    </section>
@endsection
