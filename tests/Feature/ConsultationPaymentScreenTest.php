<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Appointments\ConsultationPaymentService;
use App\Modules\Identity\ActivityLogService;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class ConsultationPaymentScreenTest extends TestCase
{
    public function test_guests_cannot_start_consultation_checkout(): void
    {
        $this->post('/citas/9/pago/stripe')->assertRedirect(route('login'));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_sessions_without_pay_permission_are_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::BOOK_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->post('/citas/9/pago/stripe')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_authorized_session_is_redirected_to_the_qr_page(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::PAY_CONSULTATIONS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $payments = Mockery::mock(ConsultationPaymentService::class);
        $payments->shouldReceive('startConsultationCheckout')->once()->with(9, 17)->andReturn([
            'payment_id' => 4,
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_demo',
        ]);
        $this->app->instance(ConsultationPaymentService::class, $payments);

        $activity = Mockery::mock(ActivityLogService::class);
        $activity->shouldReceive('record')->once();
        $this->app->instance(ActivityLogService::class, $activity);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->post('/citas/9/pago/stripe')
            ->assertRedirect(route('appointments.show', 9))
            ->assertSessionHas('consultation_checkout_url_9', 'https://checkout.stripe.com/c/pay/cs_test_demo');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_client_success_page_is_public(): void
    {
        $this->get('/citas/9/pago/exito')->assertOk()->assertSee('Pago recibido');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_stripe_webhook_endpoint_does_not_require_csrf_or_session(): void
    {
        $payments = Mockery::mock(ConsultationPaymentService::class);
        $payments->shouldReceive('handleStripeWebhook')->once()->with('{"id":"evt_1"}', 't=1,v1=sig')->andReturn([
            'resultado' => 'CONFIRMADO',
        ]);
        $this->app->instance(ConsultationPaymentService::class, $payments);

        $this->call(
            'POST',
            '/pagos/stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 't=1,v1=sig'],
            '{"id":"evt_1"}',
        )->assertOk()->assertSee('CONFIRMADO');

        $this->assertSame([], $this->app['db']->getConnections());
    }
}
