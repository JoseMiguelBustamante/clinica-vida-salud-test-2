<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class IdentityAccountScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_account_management(): void
    {
        $this->get('/usuarios')->assertRedirect(route('login'));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_the_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('permissionCodes')->once()->with($user)->andReturn([]);
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/usuarios')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_lists_accounts_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_ACCOUNTS]);
        $repository->shouldReceive('getAllAccounts')->once()->with(null)->andReturn(collect([
            (object) ['id_usuario' => 17, 'nombre_usuario' => 'admin', 'correo_acceso' => null,
                'activo' => true, 'id_rol' => 1, 'rol_nombre' => 'Administrador'],
        ]));
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/usuarios')->assertOk()->assertSee('Usuarios y roles')->assertSee('admin')
            ->assertSee('Registrar usuario')
            ->assertSee('stack-cards', false)->assertSee('account-cards', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_json_auth_is_unchanged_when_account_screens_exist(): void
    {
        $this->get('/auth/session')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->get('/usuarios')->assertRedirect(route('login'));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_create_account_validation_does_not_query_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_ACCOUNTS]);
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/usuarios/registrar')->post('/usuarios', [])->assertRedirect('/usuarios/registrar')
            ->assertSessionHasErrors('nombre_usuario');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_create_form_lists_roles_without_sql_writes(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_ACCOUNTS]);
        $repository->shouldReceive('getAllRoles')->once()->andReturn(collect([
            (object) ['id_rol' => 1, 'nombre' => 'Administrador'],
        ]));
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/usuarios/registrar')->assertOk()->assertSee('Registrar usuario')->assertSee('Administrador');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_panel_shows_account_management_when_permitted(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true]);
        $representation = ['id' => 17, 'username' => 'admin', 'email' => null,
            'role' => ['id' => 1, 'name' => 'Administrador'], 'permissions' => [PermissionCatalog::MANAGE_ACCOUNTS]];
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/panel')->assertOk()->assertSee('Usuarios y roles')->assertDontSee('usuarios.administrar');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_self_deactivation_is_blocked_without_writes(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $account = (object) ['id_usuario' => 17, 'nombre_usuario' => 'admin', 'correo_acceso' => null,
            'activo' => true, 'id_rol' => 1, 'rol_nombre' => 'Administrador'];
        $repository = Mockery::mock(IdentityRepository::class);
        $repository->shouldReceive('active')->once()->with(17)->andReturn($user);
        $repository->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_ACCOUNTS]);
        $repository->shouldReceive('getAccountById')->once()->with(17)->andReturn($account);
        $repository->shouldReceive('setAccountActive')->never();
        $this->app->instance(IdentityRepository::class, $repository);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/usuarios/17')->post('/usuarios/17/desactivar')->assertRedirect('/usuarios/17')
            ->assertSessionHasErrors(['nombre_usuario' => 'No puede desactivar su propia cuenta.']);
        $this->assertSame([], $this->app['db']->getConnections());
    }
}
