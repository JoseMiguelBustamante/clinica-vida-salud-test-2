<?php

namespace Tests\Unit;

use App\Modules\Appointments\AppointmentService;
use App\Modules\Appointments\ConsultationNotPayable;
use App\Modules\Appointments\ConsultationPaymentRepository;
use App\Modules\Appointments\ConsultationPaymentService;
use App\Modules\Appointments\InvalidPaymentWebhook;
use App\Modules\Appointments\PaymentGatewayUnavailable;
use App\Modules\Appointments\StripeCheckoutGateway;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ConsultationPaymentServiceTest extends TestCase
{
    public function test_overview_marks_programada_appointments_as_payable(): void
    {
        $appointments = Mockery::mock(AppointmentService::class);
        $appointments->shouldReceive('getAppointmentById')->once()->with(9)->andReturn((object) [
            'id_cita' => 9,
            'codigo' => 'C-9',
            'estado' => 'Programada',
        ]);

        $payments = Mockery::mock(ConsultationPaymentRepository::class);
        $payments->shouldReceive('getLatestPaymentForAppointment')->once()->with(9)->andReturn(null);

        $stripe = Mockery::mock(StripeCheckoutGateway::class);
        $stripe->shouldReceive('isConfigured')->once()->andReturn(true);

        $overview = (new ConsultationPaymentService($appointments, $payments, $stripe))->overviewForAppointment(9);

        $this->assertTrue($overview['can_start']);
        $this->assertTrue($overview['configured']);
        $this->assertSame('BOB', $overview['currency']);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_payment_receipt_marks_confirmed_payments(): void
    {
        $appointments = Mockery::mock(AppointmentService::class);
        $appointments->shouldReceive('getAppointmentById')->once()->with(9)->andReturn((object) [
            'codigo' => 'CIT-9',
            'estado' => 'Confirmada',
        ]);

        $payments = Mockery::mock(ConsultationPaymentRepository::class);
        $payments->shouldReceive('getLatestPaymentForAppointment')->once()->with(9)->andReturn((object) [
            'monto' => '150.00',
            'moneda' => 'BOB',
            'estado' => 'Confirmado',
        ]);

        $receipt = (new ConsultationPaymentService(
            $appointments,
            $payments,
            Mockery::mock(StripeCheckoutGateway::class),
        ))->paymentReceiptForAppointment(9);

        $this->assertTrue($receipt['paid']);
        $this->assertSame('CIT-9', $receipt['codigo']);
        $this->assertSame('150.00', $receipt['amount']);
        $this->assertSame('Confirmado', $receipt['payment_status']);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_start_checkout_requires_a_configured_gateway(): void
    {
        $appointments = Mockery::mock(AppointmentService::class);
        $payments = Mockery::mock(ConsultationPaymentRepository::class);
        $stripe = Mockery::mock(StripeCheckoutGateway::class);
        $stripe->shouldReceive('isConfigured')->once()->andReturn(false);

        $this->expectException(PaymentGatewayUnavailable::class);
        (new ConsultationPaymentService($appointments, $payments, $stripe))->startConsultationCheckout(9, 3);
    }

    public function test_start_checkout_rejects_non_payable_appointments(): void
    {
        $appointments = Mockery::mock(AppointmentService::class);
        $appointments->shouldReceive('getAppointmentById')->once()->with(9)->andReturn((object) [
            'id_cita' => 9,
            'codigo' => 'C-9',
            'estado' => 'Confirmada',
        ]);

        $payments = Mockery::mock(ConsultationPaymentRepository::class);
        $payments->shouldReceive('getLatestPaymentForAppointment')->once()->with(9)->andReturn(null);

        $stripe = Mockery::mock(StripeCheckoutGateway::class);
        $stripe->shouldReceive('isConfigured')->once()->andReturn(true);

        $this->expectException(ConsultationNotPayable::class);
        (new ConsultationPaymentService($appointments, $payments, $stripe))->startConsultationCheckout(9, 3);
    }

    public function test_webhook_with_invalid_signature_is_rejected_by_gateway(): void
    {
        $appointments = Mockery::mock(AppointmentService::class);
        $payments = Mockery::mock(ConsultationPaymentRepository::class);
        $stripe = Mockery::mock(StripeCheckoutGateway::class);
        $stripe->shouldReceive('parseWebhook')->once()->andThrow(new InvalidPaymentWebhook);

        $this->expectException(InvalidPaymentWebhook::class);
        (new ConsultationPaymentService($appointments, $payments, $stripe))
            ->handleStripeWebhook('{}', 't=1,v1=bad');
    }

    public function test_paid_webhook_confirms_payment_and_appointment(): void
    {
        config(['stripe.consultation_amount' => '150.00']);

        $appointments = Mockery::mock(AppointmentService::class);
        $appointments->shouldReceive('getAppointmentById')->once()->with(9)->andReturn((object) [
            'id_cita' => 9,
            'estado' => 'Programada',
        ]);
        $appointments->shouldReceive('confirmAppointment')->once()->with(9, 3);

        $payments = Mockery::mock(ConsultationPaymentRepository::class);
        $payments->shouldReceive('getPaymentByReference')->once()->with('cs_test_1')->andReturn((object) [
            'id_pago' => 44,
            'monto' => '150.00',
            'estado' => 'Pendiente',
            'id_cita' => 9,
            'id_usuario' => 3,
        ]);
        $payments->shouldReceive('markPaymentConfirmed')->once()->with(44)->andReturn(true);

        $stripe = Mockery::mock(StripeCheckoutGateway::class);
        $stripe->shouldReceive('parseWebhook')->once()->andReturn([
            'type' => 'checkout.session.completed',
            'session' => [
                'id' => 'cs_test_1',
                'payment_status' => 'paid',
                'amount_total' => 15000,
                'payment_intent' => 'pi_1',
            ],
        ]);

        DB::shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());

        $result = (new ConsultationPaymentService($appointments, $payments, $stripe))
            ->handleStripeWebhook('{"ok":true}', 't=1,v1=ok');

        $this->assertSame('CONFIRMADO', $result['resultado']);
        $this->assertSame(44, $result['pago']);
    }
}
