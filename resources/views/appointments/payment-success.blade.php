@extends('layouts.clinic')

@section('title', 'Pago recibido — Clínica Vida Salud')

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Citas / Pago</p>
        <h1 id="page-title">Pago recibido</h1>
        <p class="lead">
            Stripe aceptó el cobro. La confirmación definitiva de la cita llega por webhook;
            si aún aparece Programada, espere unos segundos y actualice.
        </p>
        @if ($payment)
            <p class="form-status" role="status">Estado actual del pago: {{ $payment->estado }}.</p>
        @endif
        <p><a class="row-action" href="{{ route('appointments.show', $appointmentId) }}">Volver a la cita</a></p>
    </section>
@endsection
