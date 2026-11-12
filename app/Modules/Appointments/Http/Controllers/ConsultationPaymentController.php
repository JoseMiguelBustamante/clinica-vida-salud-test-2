<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\AppointmentNotFound;
use App\Modules\Appointments\ConsultationNotPayable;
use App\Modules\Appointments\ConsultationPaymentService;
use App\Modules\Appointments\Http\Requests\StartConsultationPaymentRequest;
use App\Modules\Appointments\InvalidPaymentWebhook;
use App\Modules\Appointments\PaymentGatewayUnavailable;
use App\Modules\Identity\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

final class ConsultationPaymentController
{
    public function __construct(
        private ConsultationPaymentService $payments,
        private ActivityLogService $activity,
    ) {}

    public function startConsultationCheckout(StartConsultationPaymentRequest $request, int $cita): RedirectResponse
    {
        $actor = $request->attributes->get('identity');

        try {
            $result = $this->payments->startConsultationCheckout($cita, (int) $actor->id_usuario);
            $this->activity->record(
                'Creación',
                'Pagos',
                'Checkout Stripe iniciado para la cita '.$cita,
                $cita,
            );

            return redirect()->away($result['url']);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        } catch (ConsultationNotPayable) {
            return redirect()->route('appointments.show', $cita)
                ->withErrors(['q' => 'Esta consulta no admite cobro en línea en su estado actual.']);
        } catch (PaymentGatewayUnavailable) {
            return redirect()->route('appointments.show', $cita)
                ->withErrors(['q' => 'El cobro en línea no está disponible. Revise la configuración de Stripe.']);
        } catch (Throwable $exception) {
            Log::error('[ConsultationPaymentController - startConsultationCheckout] '.$exception::class);

            return redirect()->route('appointments.show', $cita)
                ->withErrors(['q' => 'No fue posible iniciar el cobro de la consulta.']);
        }
    }

    public function showConsultationPaymentSuccess(Request $request, int $cita): View|RedirectResponse
    {
        try {
            $overview = $this->payments->overviewForAppointment($cita);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        }

        return view('appointments.payment-success', [
            'appointmentId' => $cita,
            'payment' => $overview['payment'],
        ]);
    }

    public function showConsultationPaymentCancel(Request $request, int $cita): View|RedirectResponse
    {
        try {
            $this->payments->overviewForAppointment($cita);
        } catch (AppointmentNotFound) {
            return redirect()->route('appointments.index')
                ->withErrors(['q' => 'No puede realizarse la operación solicitada.']);
        }

        return view('appointments.payment-cancel', [
            'appointmentId' => $cita,
        ]);
    }

    public function handleStripeWebhook(Request $request): Response
    {
        $signature = (string) $request->header('Stripe-Signature', '');
        $payload = $request->getContent();

        try {
            $result = $this->payments->handleStripeWebhook($payload, $signature);

            return response($result['resultado'] ?? 'OK', 200);
        } catch (InvalidPaymentWebhook) {
            return response('Invalid signature', 400);
        } catch (PaymentGatewayUnavailable) {
            return response('Webhook not configured', 503);
        } catch (Throwable $exception) {
            Log::error('[ConsultationPaymentController - handleStripeWebhook] '.$exception::class);

            return response('Unable to process webhook', 500);
        }
    }
}
