<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Appointments\AppointmentRepository;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PanelDashboardService;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\Patients\PatientRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class IdentitySessionScreenTest extends TestCase
{
    public function test_the_login_form_is_public_and_does_not_query_sql(): void
    {
        $this->get('/login')->assertOk()->assertViewIs('identity.login')
            ->assertSee('Iniciar sesión')->assertSee('name="identifier"', false)
            ->assertSee('name="password"', false)->assertSee('action="/login"', false)
            ->assertSee('login-screen', false)->assertSee('Gestión de citas médicas')
            ->assertDontSee('Sistema web')->assertDontSee('action="http:', false)
            ->assertDontSee('<script', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_tls_terminating_proxy_keeps_redirects_on_https(): void
    {
        $this->call('GET', '/panel', server: [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])->assertRedirect('https://localhost/login');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_json_auth_contract_is_unchanged_alongside_the_html_form(): void
    {
        $this->get('/auth/session')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->get('/login')->assertOk();
        $this->get('/panel')->assertRedirect(route('login'));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_malformed_html_login_is_redirected_without_queries_or_throttle(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')->never();
        $this->from('/login')->post('/login', [])->assertRedirect('/login')
            ->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_html_identifier_and_password_are_not_trimmed(): void
    {
        $repository = Mockery::mock(IdentityRepository::class);
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'contrasena_hash' => 'stored hash']);
        $repository->shouldReceive('matching')->once()->with(' Mixed@Example.test ')->andReturn(new Collection([$user]));
        Hash::shouldReceive('make')->once()->with(Mockery::type('string'))->andReturn('dummy hash');
        Hash::shouldReceive('check')->once()->with(' password ', 'stored hash')->andReturn(false);
        $this->app->instance(IdentityRepository::class, $repository);
        $this->from('/login')->post('/login', ['identifier' => ' Mixed@Example.test ', 'password' => ' password '])
            ->assertRedirect('/login')->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_html_over_72_bytes_is_generic_and_never_queries(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')->never();
        $this->from('/login')->post('/login', ['identifier' => 'someone', 'password' => str_repeat('x', 73)])
            ->assertRedirect('/login')->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_html_login_establishes_a_session_and_opens_the_panel(): void
    {
        $password = ' exact password ';
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'contrasena_hash' => Hash::make($password)]);
        $representation = ['id' => 17, 'username' => 'candidate', 'email' => null,
            'role' => ['id' => 9, 'name' => 'Recepcionista'], 'permissions' => ['pacientes.registrar']];
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('matching')->once()->with('candidate')->andReturn(new Collection([$user]));
        $repository->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $repository);

        $this->post('/login', ['identifier' => 'candidate', 'password' => $password])
            ->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_panel_shows_the_role_shell_for_an_active_session(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true]);
        $representation = ['id' => 17, 'username' => 'candidate', 'email' => null,
            'role' => ['id' => 9, 'name' => 'Recepcionista'], 'permissions' => ['pacientes.registrar']];
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/panel')->assertOk()->assertViewIs('identity.panel')
            ->assertSee('Inicio')->assertSee('candidate')->assertSee('Recepcionista')
            ->assertSee('siguiente paso de esta sesión')
            ->assertDontSee('Usuarios y roles')->assertDontSee('Registrar paciente')
            ->assertDontSee('pacientes.registrar')->assertDontSee('Permisos del rol');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_panel_shows_dashboard_indicators_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true]);
        $representation = ['id' => 17, 'username' => 'admin', 'email' => null,
            'role' => ['id' => 1, 'name' => 'Administrador'], 'permissions' => [PermissionCatalog::BOOK_APPOINTMENTS, PermissionCatalog::MANAGE_PATIENTS]];
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $repository);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('countAppointmentsOnDate')->once()->andReturn(5);
        $this->app->instance(AppointmentRepository::class, $appointments);
        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('countActivePatients')->once()->andReturn(12);
        $this->app->instance(PatientRepository::class, $patients);
        $this->app->forgetInstance(PanelDashboardService::class);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/panel')->assertOk()
            ->assertSee('Indicadores')
            ->assertSee('Citas de hoy')
            ->assertSee('5')
            ->assertSee('Pacientes activos')
            ->assertSee('12');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_html_logout_returns_to_the_login_form(): void
    {
        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_html_csrf_rejects_login_without_querying(): void
    {
        $this->app->instance('env', 'csrf-verification');
        $this->post('/login', ['identifier' => 'someone', 'password' => 'password'])->assertStatus(419);
        $this->assertSame([], $this->app['db']->getConnections());
        $this->assertGuest();
    }

    public function test_html_operational_failures_are_generic(): void
    {
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('matching')->once()->andThrow(new \RuntimeException('SQL secret hash'));
        $this->app->instance(IdentityRepository::class, $repository);
        $this->from('/login')->post('/login', ['identifier' => 'someone', 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['identifier' => 'No fue posible completar el acceso.']);
        $this->assertGuest();
    }
}
