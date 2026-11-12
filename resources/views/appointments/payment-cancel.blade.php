@extends('layouts.clinic')

@section('title', 'Pago cancelado — Clínica Vida Salud')

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Atención / Citas / Pago</p>
        <h1 id="page-title">Pago cancelado</h1>
        <p class="lead">No se cobró la consulta. Puede reintentar el pago desde la ficha de la cita.</p>
        <p><a class="row-action" href="{{ route('appointments.show', $appointmentId) }}">Volver a la cita</a></p>
    </section>
@endsection
