<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Appointments\AppointmentRepository;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\MedicalStaff\SpecialtyRepository;
use App\Modules\Patients\PatientRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class AvailabilityScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_availability(): void
    {
        $this->get('/disponibilidad')->assertRedirect(route('login'));
        $this->get('/appointments')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_the_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/disponibilidad')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_opens_the_lookup_without_sql_for_slots(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_AVAILABILITY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $specialties->shouldReceive('getActiveSpecialtiesWithPhysicians')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getActivePhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Ana', 'apellidos' => 'Martínez', 'activo' => true, 'registro_profesional' => 'MP-1'],
        ]));
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'inicio' => '2026-11-03T09:00:00+00:00',
                'fin' => '2026-11-03T09:30:00+00:00',
                'estado' => 'Programada',
                'id_medico' => 4,
                'paciente_nombres' => 'Lucía',
                'paciente_apellidos' => 'Vargas',
                'medico_nombres' => 'Ana',
                'medico_apellidos' => 'Martínez',
            ],
        ]));
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/disponibilidad')->assertOk()->assertSee('Disponibilidad')
                ->assertSee('Pediatría')->assertSee('no lo reserva', false)
                ->assertSee('Consultar')->assertSee('Semana')->assertSee('Lunes')
                ->assertSee('Todos los médicos')->assertSee('Lucía')->assertSee('Vargas')
                ->assertSee('week-day-name-short', false)->assertSee('booking-layout', false)
                ->assertDontSee('Nuevo turno')->assertDontSee('Guardar cita')
                ->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_panel_shows_availability_when_permitted(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true]);
        $representation = ['id' => 17, 'username' => 'recepcion', 'email' => null,
            'role' => ['id' => 2, 'name' => 'Recepcionista'],
            'permissions' => [PermissionCatalog::CONSULT_AVAILABILITY]];
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('representation')->once()->with($user)->andReturn($representation);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/panel')->assertOk()->assertSee('Disponibilidad')->assertDontSee('Horarios')
            ->assertDontSee('Pendiente — aún no está implementado.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_past_date_is_rejected_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_AVAILABILITY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/disponibilidad')->get('/disponibilidad?fecha=2020-01-01')
            ->assertRedirect('/disponibilidad')->assertSessionHasErrors('fecha');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_booker_can_pick_a_calendar_slot(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AVAILABILITY,
            PermissionCatalog::BOOK_APPOINTMENTS,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $specialties->shouldReceive('getActiveSpecialtiesWithPhysicians')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getActivePhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Ana', 'apellidos' => 'Martínez', 'activo' => true, 'registro_profesional' => 'MP-1'],
        ]));
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect());
        $this->app->instance(AppointmentRepository::class, $appointments);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getActivePatients')->never();
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/disponibilidad')->assertOk()->assertSee('Nueva cita')->assertSee('Nuevo turno')
                ->assertSee('Ampliar horas')->assertDontSee('Guardar cita')->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_calendar_booking_panel_opens_without_sql_for_the_slot(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AVAILABILITY,
            PermissionCatalog::BOOK_APPOINTMENTS,
            PermissionCatalog::MANAGE_PATIENTS,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $specialties->shouldReceive('getActiveSpecialtiesWithPhysicians')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getActivePhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Ana', 'apellidos' => 'Martínez', 'activo' => true, 'registro_profesional' => 'MP-1'],
        ]));
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect());
        $this->app->instance(AppointmentRepository::class, $appointments);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getActivePatients')->once()->with(null)->andReturn(collect([
            (object) ['id_paciente' => 8, 'nombres' => 'María', 'apellidos' => 'López', 'ci' => '1001'],
        ]));
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/disponibilidad?nueva=1&fecha=2026-11-02&hora_inicio=09:00&hora_fin=09:30&id_paciente=8')
                ->assertOk()
                ->assertSee('Nueva cita')
                ->assertSee('Lunes 2 de noviembre')
                ->assertSee('09:00')
                ->assertSee('09:30')
                ->assertSee('María')
                ->assertSee('Paciente registrado')
                ->assertSee('Guardar cita')
                ->assertSee('Registrar paciente')
                ->assertSee('Buscar paciente')
                ->assertSee('Cargar médicos')
                ->assertSee('Ver en el calendario')
                ->assertSee('Nombre o cédula')
                ->assertSee('Elija especialidad para cargar médicos')
                ->assertSee('Nuevo turno')
                ->assertSee('booking-layout is-open', false)
                ->assertDontSee('Confirmar programación')
                ->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_patient_search_filters_the_booking_list_without_sql_beyond_the_lookup(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AVAILABILITY,
            PermissionCatalog::BOOK_APPOINTMENTS,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $specialties->shouldReceive('getActiveSpecialtiesWithPhysicians')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getActivePhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Ana', 'apellidos' => 'Martínez', 'activo' => true, 'registro_profesional' => 'MP-1'],
        ]));
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect());
        $this->app->instance(AppointmentRepository::class, $appointments);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getActivePatients')->once()->with(null)->andReturn(collect([
            (object) ['id_paciente' => 9, 'nombres' => 'Elena', 'apellidos' => 'Vargas', 'ci' => '1002'],
            (object) ['id_paciente' => 10, 'nombres' => 'Mario', 'apellidos' => 'Soto', 'ci' => '1003'],
        ]));
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/disponibilidad?nueva=1&fecha=2026-11-03&hora_inicio=08:00&hora_fin=08:30&q=ele')
                ->assertOk()
                ->assertSee('Elena Vargas')
                ->assertSee('Elegir')
                ->assertDontSee('Ningún paciente coincide con «ele»')
                ->assertSee('Ampliar horas')
                ->assertSee('sin recargar')
                ->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_partial_patient_search_lists_matches_to_pick_without_sql(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AVAILABILITY,
            PermissionCatalog::BOOK_APPOINTMENTS,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $specialties->shouldReceive('getActiveSpecialtiesWithPhysicians')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getActivePhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Ana', 'apellidos' => 'Martínez', 'activo' => true, 'registro_profesional' => 'MP-1'],
        ]));
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect());
        $this->app->instance(AppointmentRepository::class, $appointments);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getActivePatients')->once()->with(null)->andReturn(collect([
            (object) ['id_paciente' => 8, 'nombres' => 'Jose Miguel', 'apellidos' => 'Bustamante Condori', 'ci' => null],
            (object) ['id_paciente' => 11, 'nombres' => 'Josefina', 'apellidos' => 'Rojas', 'ci' => '2001'],
        ]));
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/disponibilidad?nueva=1&fecha=2026-11-03&hora_inicio=08:00&hora_fin=08:30&q=Jose')
                ->assertOk()
                ->assertSee('Jose Miguel Bustamante Condori')
                ->assertSee('Josefina Rojas')
                ->assertSee('Elegir')
                ->assertDontSee('Paciente registrado')
                ->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_longer_form_interval_is_drawn_on_the_calendar(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AVAILABILITY,
            PermissionCatalog::BOOK_APPOINTMENTS,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $specialties->shouldReceive('getActiveSpecialtiesWithPhysicians')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getActivePhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Ana', 'apellidos' => 'Martínez', 'activo' => true, 'registro_profesional' => 'MP-1'],
        ]));
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect());
        $this->app->instance(AppointmentRepository::class, $appointments);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getActivePatients')->once()->with(null)->andReturn(collect());
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/disponibilidad?nueva=1&fecha=2026-11-03&hora_inicio=08:00&hora_fin=10:00')
                ->assertOk()
                ->assertSee('Martes 3 de noviembre · 08:00 – 10:00')
                ->assertSee('08:00 – 10:00')
                ->assertSee('Ver en el calendario')
                ->assertSee('this.form.submit()', false)
                ->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_an_inverted_clock_is_repaired_to_a_half_hour(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 1, 15, 0, 0, 'UTC'));
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AVAILABILITY,
            PermissionCatalog::BOOK_APPOINTMENTS,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $specialties->shouldReceive('getActiveSpecialtiesWithPhysicians')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getActivePhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Ana', 'apellidos' => 'Martínez', 'activo' => true, 'registro_profesional' => 'MP-1'],
        ]));
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect());
        $this->app->instance(AppointmentRepository::class, $appointments);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getActivePatients')->once()->with(null)->andReturn(collect());
        $this->app->instance(PatientRepository::class, $patients);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        try {
            $this->get('/disponibilidad?nueva=1&fecha=2026-11-03&hora_inicio=10:00&hora_fin=08:30')
                ->assertOk()
                ->assertSee('Martes 3 de noviembre · 10:00 – 10:30')
                ->assertDontSee('<script', false);
            $this->assertSame([], $this->app['db']->getConnections());
        } finally {
            Carbon::setTestNow();
        }
    }
}
