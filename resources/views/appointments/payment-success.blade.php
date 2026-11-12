@extends('layouts.clinic')

@section('title', 'Pago recibido — Clínica Vida Salud')

@section('content')
    <section class="app-page" aria-labelledby="page-title">
        <h1 id="page-title">Pago recibido</h1>
        <p class="lead">
            Gracias. El cobro de la consulta fue aceptado por Stripe.
            Ya puede cerrar esta ventana; en recepción se actualizará el estado de la cita.
        </p>
    </section>
@endsection
