<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\ActivityLogRepository;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class ActivityLogScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_the_activity_log(): void
    {
        $this->get('/bitacora')->assertRedirect(route('login'));
        $this->get('/audit')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_the_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_ACCOUNTS]);
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/bitacora')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_lists_activity_without_sql(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_ACTIVITY_LOG]);
        $identities->shouldReceive('getAllAccounts')->once()->with(null)->andReturn(collect([
            (object) ['id_usuario' => 17, 'nombre_usuario' => 'admin', 'correo_acceso' => null,
                'activo' => true, 'id_rol' => 1, 'rol_nombre' => 'Administrador'],
        ]));
        $this->app->instance(IdentityRepository::class, $identities);

        $logs = Mockery::mock(ActivityLogRepository::class);
        $logs->shouldReceive('countActivityLogs')->once()->with(null, null, null, '2026-10-26', '2026-11-01')->andReturn(1);
        $logs->shouldReceive('getActivityLogs')->once()->with(null, null, null, '2026-10-26', '2026-11-01', 8, 0)->andReturn(collect([
            (object) [
                'id_bitacora' => 4,
                'fecha_hora' => '2026-11-01T14:32:00+00:00',
                'accion' => 'Actualización',
                'modulo' => 'Pacientes',
                'detalle' => 'Teléfono actualizado',
                'direccion_ip' => '192.168.1.10',
                'actor_nombre' => 'admin',
                'actor_rol' => 'Administrador',
                'id_usuario' => 17,
                'id_registro' => 12,
                'cambios' => '[{"campo":"Teléfono","valor_anterior":"77778888","valor_nuevo":"77779999"}]',
            ],
        ]));
        $this->app->instance(ActivityLogRepository::class, $logs);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/bitacora')->assertOk()->assertSee('Bitácora de actividad')
                ->assertSee('Solo consulta')->assertSee('Teléfono actualizado')
                ->assertSee('77778888')->assertSee('77779999')->assertSee('Ver cambios')
                ->assertSee('value="2026-10-26"', false)->assertSee('value="2026-11-01"', false)
                ->assertSee('Filtrar')->assertSee('Limpiar')
                ->assertSee('activity-cards', false)->assertSee('activity-table-wrap', false)
                ->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_chosen_dates_replace_the_default_week_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_ACTIVITY_LOG]);
        $identities->shouldReceive('getAllAccounts')->once()->with(null)->andReturn(collect([
            (object) ['id_usuario' => 17, 'nombre_usuario' => 'admin', 'correo_acceso' => null,
                'activo' => true, 'id_rol' => 1, 'rol_nombre' => 'Administrador'],
        ]));
        $this->app->instance(IdentityRepository::class, $identities);

        $logs = Mockery::mock(ActivityLogRepository::class);
        $logs->shouldReceive('countActivityLogs')->once()->with(null, null, null, '2026-10-01', '2026-10-15')->andReturn(0);
        $logs->shouldReceive('getActivityLogs')->once()->with(null, null, null, '2026-10-01', '2026-10-15', 8, 0)->andReturn(collect());
        $this->app->instance(ActivityLogRepository::class, $logs);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/bitacora?desde=2026-10-01&hasta=2026-10-15')->assertOk()
            ->assertSee('value="2026-10-01"', false)->assertSee('value="2026-10-15"', false)
            ->assertSee('No hay movimientos que coincidan con la búsqueda.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_activity_log_rejects_an_invalid_action_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_ACTIVITY_LOG]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/bitacora')->get('/bitacora?accion=Borrado')
            ->assertRedirect('/bitacora')->assertSessionHasErrors('accion');
        $this->assertSame([], $this->app['db']->getConnections());
    }
}
