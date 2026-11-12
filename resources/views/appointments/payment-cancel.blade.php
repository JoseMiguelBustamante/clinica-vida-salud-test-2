@extends('layouts.clinic')

@section('title', 'Pago cancelado — Clínica Vida Salud')

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <p class="app-breadcrumb">Clínica Vida Salud / Cobro</p>
        <h1 id="page-title">Pago no completado</h1>
        <div class="form-errors" role="alert">
            <p>No se cobró la consulta. Puede cerrar esta ventana y pedir en recepción un QR nuevo.</p>
        </div>
        <p class="lead">Si cerró Stripe por error, recepción puede generar otro cobro en la ficha de la cita.</p>
    </section>
@endsection
