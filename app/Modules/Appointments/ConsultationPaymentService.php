<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ConsultationPaymentService
{
    public function __construct(
        private AppointmentService $appointments,
        private ConsultationPaymentRepository $payments,
        private StripeCheckoutGateway $stripe,
    ) {}

    /**
     * @return array{payment: object|null, can_start: bool, amount: string, currency: string, configured: bool}
     */
    public function overviewForAppointment(int $appointmentId): array
    {
        $appointment = $this->appointments->getAppointmentById($appointmentId);
        $payment = $this->payments->getLatestPaymentForAppointment($appointmentId);

        return [
            'payment' => $payment,
            'can_start' => $this->isPayable($appointment, $payment),
            'amount' => $this->consultationAmount(),
            'currency' => strtoupper((string) config('stripe.currency', 'bob')),
            'configured' => $this->stripe->isConfigured(),
        ];
    }

    /**
     * @return array{payment_id: int, url: string}
     */
    public function startConsultationCheckout(int $appointmentId, int $actorUserId): array
    {
        if (! $this->stripe->isConfigured()) {
            throw new PaymentGatewayUnavailable;
        }

        $appointment = $this->appointments->getAppointmentById($appointmentId);
        $existing = $this->payments->getLatestPaymentForAppointment($appointmentId);
        if (! $this->isPayable($appointment, $existing)) {
            throw new ConsultationNotPayable;
        }

        if ($existing !== null && (string) $existing->estado === 'Pendiente' && is_string($existing->referencia_externa)) {
            $this->stripe->expireCheckoutSession((string) $existing->referencia_externa);
            $this->payments->markPaymentRejected((int) $existing->id_pago);
        }

        $amount = $this->consultationAmount();
        $currency = strtolower((string) config('stripe.currency', 'bob'));
        $expiresAt = time() + max(5, (int) config('stripe.checkout_expires_minutes', 30)) * 60;
        $successUrl = route('appointments.payment.success', ['cita' => $appointmentId], absolute: true)
            .'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('appointments.payment.cancel', ['cita' => $appointmentId], absolute: true);

        $session = $this->stripe->createCheckoutSession([
            'amount_minor' => $this->toMinorUnits($amount),
            'currency' => $currency,
            'product_name' => 'Consulta médica '.$appointment->codigo,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => $expiresAt,
            'metadata' => [
                'id_cita' => (string) $appointmentId,
                'codigo' => (string) $appointment->codigo,
            ],
        ]);

        try {
            $paymentId = DB::transaction(function () use ($appointmentId, $actorUserId, $amount, $currency, $session): int {
                return $this->payments->createPendingPayment(
                    $appointmentId,
                    $actorUserId,
                    $amount,
                    $currency,
                    $session['id'],
                    Carbon::now('UTC')->toIso8601String(),
                );
            });
        } catch (\Throwable $exception) {
            $this->stripe->expireCheckoutSession($session['id']);
            Log::error('[ConsultationPaymentService - startConsultationCheckout] '.$exception::class);
            throw $exception;
        }

        return ['payment_id' => $paymentId, 'url' => $session['url']];
    }

    /**
     * @return array{resultado: string}
     */
    public function handleStripeWebhook(string $payload, string $signatureHeader): array
    {
        $event = $this->stripe->parseWebhook($payload, $signatureHeader);
        $session = $event['session'];

        return match ($event['type']) {
            'checkout.session.completed' => ($session['payment_status'] ?? null) === 'paid'
                ? $this->settleCompletedSession($session)
                : ['resultado' => 'EN_ESPERA'],
            'checkout.session.async_payment_succeeded' => $this->settleCompletedSession($session),
            'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->rejectExpiredSession($session['id']),
            default => ['resultado' => 'IGNORADO', 'tipo' => $event['type']],
        };
    }

    /**
     * @param  array{id: string, payment_status?: string|null, amount_total?: int|null, payment_intent?: string|null}  $session
     * @return array{resultado: string, pago?: int}
     */
    private function settleCompletedSession(array $session): array
    {
        $payment = $this->payments->getPaymentByReference($session['id']);
        if ($payment === null) {
            Log::warning('[ConsultationPaymentService - settleCompletedSession] unknown session');

            return ['resultado' => 'IGNORADO'];
        }

        if (in_array((string) $payment->estado, ['Confirmado', 'Devuelto', 'Rechazado'], true)) {
            return ['resultado' => 'YA_PROCESADO', 'pago' => (int) $payment->id_pago];
        }

        $expectedMinor = $this->toMinorUnits((string) $payment->monto);
        if (($session['amount_total'] ?? null) !== $expectedMinor) {
            if (is_string($session['payment_intent'] ?? null) && $session['payment_intent'] !== '') {
                $this->stripe->refundPaymentIntent(
                    (string) $session['payment_intent'],
                    'refund-'.$session['id'],
                );
            }
            $this->payments->markPaymentRefunded((int) $payment->id_pago);
            Log::warning('[ConsultationPaymentService - settleCompletedSession] amount mismatch');

            return ['resultado' => 'DEVUELTO', 'pago' => (int) $payment->id_pago];
        }

        DB::transaction(function () use ($payment): void {
            if (! $this->payments->markPaymentConfirmed((int) $payment->id_pago)) {
                return;
            }

            $appointment = $this->appointments->getAppointmentById((int) $payment->id_cita);
            if ((string) $appointment->estado === 'Programada') {
                $this->appointments->confirmAppointment((int) $payment->id_cita, (int) $payment->id_usuario);
            }
        });

        return ['resultado' => 'CONFIRMADO', 'pago' => (int) $payment->id_pago];
    }

    /**
     * @return array{resultado: string, pago?: int}
     */
    private function rejectExpiredSession(string $sessionId): array
    {
        $payment = $this->payments->getPaymentByReference($sessionId);
        if ($payment === null || (string) $payment->estado !== 'Pendiente') {
            return ['resultado' => 'IGNORADO'];
        }

        $this->payments->markPaymentRejected((int) $payment->id_pago);

        return ['resultado' => 'RECHAZADO', 'pago' => (int) $payment->id_pago];
    }

    private function isPayable(object $appointment, ?object $payment): bool
    {
        if ((string) $appointment->estado !== 'Programada') {
            return false;
        }

        if ($payment !== null && (string) $payment->estado === 'Confirmado') {
            return false;
        }

        return true;
    }

    private function consultationAmount(): string
    {
        $raw = (string) config('stripe.consultation_amount', '150.00');
        $amount = number_format((float) $raw, 2, '.', '');
        if ((float) $amount <= 0) {
            return '150.00';
        }

        return $amount;
    }

    private function toMinorUnits(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
