@extends('layouts.clinic')

@section('title', 'Pago recibido — Clínica Vida Salud')

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Clínica Vida Salud / Cobro</p>
        <h1 id="page-title">Pago recibido</h1>

        <p class="form-status" role="status">
            @if (($receipt['paid'] ?? false) === true)
                El pago de la consulta fue confirmado correctamente.
            @else
                Stripe aceptó el cobro. En unos segundos recepción verá el estado actualizado.
            @endif
        </p>

        <article class="list-card detail-card">
            <dl class="detail-list">
                @if (! empty($receipt['codigo']))
                    <div>
                        <dt>Cita</dt>
                        <dd>{{ $receipt['codigo'] }}</dd>
                    </div>
                @endif
                @if (! empty($receipt['amount']) && ! empty($receipt['currency']))
                    <div>
                        <dt>Monto pagado</dt>
                        <dd>{{ $receipt['amount'] }} {{ $receipt['currency'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt>Estado del pago</dt>
                    <dd>
                        <span class="status-pill {{ ($receipt['paid'] ?? false) ? 'is-confirmada' : 'is-programada' }}">
                            {{ $receipt['payment_status'] ?? 'Procesando' }}
                        </span>
                    </dd>
                </div>
                @if (! empty($receipt['estado_cita']))
                    <div>
                        <dt>Estado de la cita</dt>
                        <dd>
                            <span class="status-pill {{ $receipt['estado_cita'] === 'Confirmada' ? 'is-confirmada' : 'is-programada' }}">
                                {{ $receipt['estado_cita'] }}
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>
        </article>

        <p class="lead" style="margin-top:1rem">
            Gracias. Ya puede cerrar esta ventana y volver a recepción.
        </p>
    </section>
@endsection
