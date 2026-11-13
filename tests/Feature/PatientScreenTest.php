<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\Patients\PatientRepository;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class PatientScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_patient_records(): void
    {
        $this->get('/pacientes')->assertRedirect(route('login'));
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
        $this->get('/pacientes')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_lists_patients_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_PATIENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getAllPatients')->once()->with(null)->andReturn(collect([
            (object) ['id_paciente' => 8, 'ci' => '123', 'nombres' => 'Ana', 'apellidos' => 'Rojas',
                'telefono' => null, 'activo' => true],
        ]));
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/pacientes')->assertOk()->assertSee('Pacientes')->assertSee('Ana')
            ->assertSee('Ana Rojas')->assertSee('Registrar paciente')->assertSee('Mostrando 1 paciente')
            ->assertSee('stack-cards', false)->assertSee('patient-cards', false)
            ->assertDontSee('<script', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_json_auth_is_unchanged_when_patient_screens_exist(): void
    {
        $this->get('/auth/session')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->get('/pacientes')->assertRedirect(route('login'));
        $this->get('/patients')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_create_patient_validation_does_not_query_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_PATIENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/pacientes/registrar')->post('/pacientes', [])->assertRedirect('/pacientes/registrar')
            ->assertSessionHasErrors('nombres');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_create_form_loads_portal_account_email_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MANAGE_PATIENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $account = (object) [
            'id_usuario' => 42,
            'nombre_usuario' => 'Jose Miguel BC',
            'correo_acceso' => 'paciente@example.com',
            'rol_nombre' => 'Paciente',
        ];
        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getLinkablePatientAccounts')->once()->with(null)->andReturn(collect([$account]));
        $patients->shouldReceive('getLinkablePatientAccountById')->once()->with(42, null)->andReturn($account);
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/pacientes/registrar?cuenta=42')->assertOk()
            ->assertSee('Registrar paciente')
            ->assertSee('paciente@example.com')
            ->assertSee('Jose Miguel BC')
            ->assertSee('Cargar correo y vínculo')
            ->assertDontSee('<script', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_panel_shows_patient_management_when_permitted(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true]);
        $representation = ['id' => 17, 'username' => 'recepcion', 'email' => null,
            'role' => ['id' => 2, 'name' => 'Recepcionista'], 'permissions' => [PermissionCatalog::MANAGE_PATIENTS]];
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/panel')->assertOk()->assertSee('Pacientes')
            ->assertDontSee('Usuarios y roles')->assertDontSee('Médicos')->assertDontSee('Especialidades')
            ->assertDontSee('Horarios');
        $this->assertSame([], $this->app['db']->getConnections());
    }
}
