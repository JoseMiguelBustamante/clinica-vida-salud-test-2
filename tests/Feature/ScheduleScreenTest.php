<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\Schedules\ScheduleRepository;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class ScheduleScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_schedules(): void
    {
        $this->get('/horarios')->assertRedirect(route('login'));
        $this->get('/schedules')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_the_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/horarios')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_lists_schedules_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_SCHEDULES]);
        $this->app->instance(IdentityRepository::class, $identities);

        $schedules = Mockery::mock(ScheduleRepository::class);
        $schedules->shouldReceive('getAllSchedules')->once()->with(null)->andReturn(collect([
            (object) [
                'id_horario' => 9,
                'dia_semana' => 'Lunes',
                'hora_inicio' => '08:00:00',
                'hora_fin' => '12:00:00',
                'duracion_cita_minutos' => 30,
                'activo' => true,
                'id_medico' => 4,
                'nombres' => 'Luis',
                'apellidos' => 'Soto',
                'registro_profesional' => 'MP-1',
            ],
        ]));
        $this->app->instance(ScheduleRepository::class, $schedules);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/horarios')->assertOk()->assertSee('Horarios de atención')->assertSee('Luis')
            ->assertSee('08:00')->assertSee('Registrar horario')
            ->assertSee('stack-cards', false)->assertSee('schedule-cards', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_panel_shows_schedules_when_permitted(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true]);
        $representation = ['id' => 17, 'username' => 'admin', 'email' => null,
            'role' => ['id' => 1, 'name' => 'Administrador'],
            'permissions' => [PermissionCatalog::MANAGE_SCHEDULES]];
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/panel')->assertOk()->assertSee('Horarios')->assertDontSee('Usuarios y roles');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_create_schedule_validation_does_not_query_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_SCHEDULES]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/horarios/registrar')->post('/horarios', [])->assertRedirect('/horarios/registrar')
            ->assertSessionHasErrors(['id_medico', 'dia_semana', 'hora_inicio']);
        $this->assertSame([], $this->app['db']->getConnections());
    }
}
