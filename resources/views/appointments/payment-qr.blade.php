@extends('layouts.clinic')

@section('title', 'QR de cobro — Clínica Vida Salud')

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Citas / Cobro</p>
        <div class="page-toolbar">
            <div>
                <h1 id="page-title">Cobro de consulta</h1>
                <p class="lead">
                    Mostrá el QR al cliente. El personal no paga: el paciente escanea y completa el cobro en su teléfono.
                </p>
            </div>
            <nav class="row-actions" aria-label="Acciones">
                <a class="row-action" href="{{ route('appointments.show', $appointment->id_cita) }}">Volver a la cita</a>
            </nav>
        </div>

        @if (session('status'))
            <p class="form-status" role="status">{{ session('status') }}</p>
        @endif

        <article class="list-card detail-card">
            <dl class="detail-list">
                <div>
                    <dt>Cita</dt>
                    <dd>{{ $appointment->codigo }}</dd>
                </div>
                <div>
                    <dt>Monto</dt>
                    <dd>{{ $amount }} {{ $currency }}</dd>
                </div>
                <div>
                    <dt>Estado del pago</dt>
                    <dd>{{ $payment->estado }}</dd>
                </div>
            </dl>

            @if ($payment->estado === 'Confirmado')
                <p class="form-status" role="status">
                    Pago recibido: {{ $amount }} {{ $currency }}. El cobro quedó Confirmado
                    @if ($appointment->estado === 'Confirmada')
                        y la cita ya está Confirmada.
                    @else
                        ; vuelva a la ficha para verificar el estado de la cita.
                    @endif
                </p>
                <p><a class="row-action" href="{{ route('appointments.show', $appointment->id_cita) }}">Ver ficha de la cita</a></p>
            @elseif ($checkoutUrl)
                <div style="display:grid;gap:1rem;justify-items:start;margin-top:1rem">
                    <img
                        src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={{ urlencode($checkoutUrl) }}"
                        alt="Código QR para pagar la consulta con Stripe"
                        width="220"
                        height="220"
                    >
                    <p class="list-meta">El cliente escanea este QR o abre el enlace en su celular.</p>
                    <p><a class="row-action" href="{{ $checkoutUrl }}" target="_blank" rel="noopener noreferrer">Abrir link de pago</a></p>
                    <p class="list-meta" style="word-break:break-all">{{ $checkoutUrl }}</p>
                </div>
                <meta http-equiv="refresh" content="8">
                <p class="list-meta">Esta pantalla se actualiza sola. Cuando Stripe confirme el webhook, el estado cambiará.</p>
            @else
                <p class="list-meta">No hay un link de Checkout vigente. Generá un QR nuevo desde la ficha de la cita.</p>
            @endif
        </article>
    </section>
@endsection
