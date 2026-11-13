<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\MedicalStaff\PhysicianRepository;
use App\Modules\MedicalStaff\SpecialtyRepository;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class MedicalStaffScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_medical_staff(): void
    {
        $this->get('/especialidades')->assertRedirect(route('login'));
        $this->get('/medicos')->assertRedirect(route('login'));
        $this->get('/medical-staff')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_the_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->times(2)->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->times(2)->with($user)->andReturn([]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/especialidades')->assertForbidden();
        $this->get('/medicos')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_lists_specialties_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_SPECIALTIES]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getAllSpecialties')->once()->with(null)->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'descripcion' => null, 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/especialidades')->assertOk()->assertSee('Especialidades')->assertSee('Pediatría')
            ->assertSee('Registrar especialidad')
            ->assertSee('stack-cards', false)->assertSee('specialty-cards', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_lists_physicians_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_PHYSICIANS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $row = (object) ['id_medico' => 4, 'registro_profesional' => 'MP-1', 'nombres' => 'Luis',
            'apellidos' => 'Soto', 'activo' => true];
        $physicians = Mockery::mock(PhysicianRepository::class);
        $physicians->shouldReceive('getAllPhysicians')->once()->with(null)->andReturn(collect([$row]));
        $physicians->shouldReceive('getSpecialtiesForPhysicians')->once()->with([4])->andReturn(collect([
            '4' => collect([(object) ['id_medico' => 4, 'nombre' => 'Pediatría']]),
        ]));
        $this->app->instance(PhysicianRepository::class, $physicians);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/medicos')->assertOk()->assertSee('Médicos')->assertSee('Luis Soto')->assertSee('Pediatría')
            ->assertSee('Registrar médico')->assertSee('Mostrando 1 médico')
            ->assertSee('stack-cards', false)->assertSee('physician-cards', false)
            ->assertDontSee('<script', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_panel_shows_medical_staff_when_permitted(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true]);
        $representation = ['id' => 17, 'username' => 'admin', 'email' => null,
            'role' => ['id' => 1, 'name' => 'Administrador'],
            'permissions' => [PermissionCatalog::MANAGE_PHYSICIANS, PermissionCatalog::MANAGE_SPECIALTIES]];
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/panel')->assertOk()->assertSee('Médicos')->assertSee('Especialidades')
            ->assertDontSee('Usuarios y roles');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_create_specialty_validation_does_not_query_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_SPECIALTIES]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/especialidades/registrar')->post('/especialidades', [])->assertRedirect('/especialidades/registrar')
            ->assertSessionHasErrors('nombre');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_create_physician_form_keeps_specialty_checkboxes_in_a_choice_list(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_PHYSICIANS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 2, 'nombre' => 'Dermatología', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $physicians = Mockery::mock(PhysicianRepository::class);
        $physicians->shouldReceive('getLinkableStaffAccounts')->once()->with(null)->andReturn(collect());
        $this->app->instance(PhysicianRepository::class, $physicians);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/medicos/registrar')->assertOk()->assertSee('Registrar médico')->assertSee('Dermatología')
            ->assertSee('choice-fieldset', false)->assertSee('choice-list', false)
            ->assertDontSee('<fieldset class="field"', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_create_physician_validation_does_not_query_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_PHYSICIANS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/medicos/registrar')->post('/medicos', [])->assertRedirect('/medicos/registrar')
            ->assertSessionHasErrors(['nombres', 'especialidades']);
        $this->assertSame([], $this->app['db']->getConnections());
    }
}
