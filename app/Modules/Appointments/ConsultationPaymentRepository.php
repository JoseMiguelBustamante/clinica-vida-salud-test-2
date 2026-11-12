<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use Illuminate\Support\Facades\DB;

final class ConsultationPaymentRepository
{
    public function getConfirmedPaymentForAppointment(int $appointmentId): ?object
    {
        return DB::table('pago_consulta')
            ->select('id_pago', 'fecha_hora', 'monto', 'moneda', 'metodo', 'estado', 'referencia_externa', 'id_cita', 'id_usuario')
            ->where('id_cita', $appointmentId)
            ->where('estado', 'Confirmado')
            ->orderByDesc('id_pago')
            ->first();
    }

    public function getPendingPaymentForAppointment(int $appointmentId): ?object
    {
        return DB::table('pago_consulta')
            ->select('id_pago', 'fecha_hora', 'monto', 'moneda', 'metodo', 'estado', 'referencia_externa', 'id_cita', 'id_usuario')
            ->where('id_cita', $appointmentId)
            ->where('estado', 'Pendiente')
            ->orderByDesc('id_pago')
            ->first();
    }

    public function getPaymentByReference(string $reference): ?object
    {
        return DB::table('pago_consulta')
            ->select('id_pago', 'fecha_hora', 'monto', 'moneda', 'metodo', 'estado', 'referencia_externa', 'id_cita', 'id_usuario')
            ->where('referencia_externa', $reference)
            ->first();
    }

    public function getLatestPaymentForAppointment(int $appointmentId): ?object
    {
        return DB::table('pago_consulta')
            ->select('id_pago', 'fecha_hora', 'monto', 'moneda', 'metodo', 'estado', 'referencia_externa', 'id_cita', 'id_usuario')
            ->where('id_cita', $appointmentId)
            ->orderByDesc('id_pago')
            ->first();
    }

    public function createPendingPayment(
        int $appointmentId,
        int $actorUserId,
        string $amount,
        string $currency,
        string $reference,
        string $createdAt,
    ): int {
        return (int) DB::table('pago_consulta')->insertGetId([
            'fecha_hora' => $createdAt,
            'monto' => $amount,
            'moneda' => strtoupper($currency),
            'metodo' => 'STRIPE',
            'estado' => 'Pendiente',
            'referencia_externa' => $reference,
            'id_cita' => $appointmentId,
            'id_usuario' => $actorUserId,
        ], 'id_pago');
    }

    public function markPaymentConfirmed(int $paymentId): bool
    {
        return DB::table('pago_consulta')
            ->where('id_pago', $paymentId)
            ->where('estado', 'Pendiente')
            ->update(['estado' => 'Confirmado']) > 0;
    }

    public function markPaymentRejected(int $paymentId): bool
    {
        return DB::table('pago_consulta')
            ->where('id_pago', $paymentId)
            ->where('estado', 'Pendiente')
            ->update(['estado' => 'Rechazado']) > 0;
    }

    public function markPaymentRefunded(int $paymentId): bool
    {
        return DB::table('pago_consulta')
            ->where('id_pago', $paymentId)
            ->whereIn('estado', ['Pendiente', 'Confirmado'])
            ->update(['estado' => 'Devuelto']) > 0;
    }
}
