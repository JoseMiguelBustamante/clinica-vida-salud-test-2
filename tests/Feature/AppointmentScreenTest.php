<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Appointments\AppointmentRepository;
use App\Modules\Identity\IdentityRepository;
use App\Modules\Identity\PermissionCatalog;
use App\Modules\MedicalStaff\SpecialtyRepository;
use App\Modules\Patients\PatientRepository;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class AppointmentScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_booking(): void
    {
        $this->get('/citas/programar')->assertRedirect(route('login'));
        $this->get('/appointments')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_the_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_AVAILABILITY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/programar')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_opens_the_booking_form_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::BOOK_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getActivePatients')->once()->with(null)->andReturn(collect([
            (object) ['id_paciente' => 8, 'nombres' => 'Ana', 'apellidos' => 'Rojas', 'ci' => '123'],
        ]));
        $this->app->instance(PatientRepository::class, $patients);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getActiveSpecialties')->once()->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/programar')->assertOk()->assertSee('Programar cita médica')
            ->assertSee('Ana')->assertSee('Pediatría')->assertSee('Confirmar programación');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_booking_validation_does_not_query_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::BOOK_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/citas/programar')->post('/citas', [])->assertRedirect('/citas/programar')
            ->assertSessionHasErrors('id_paciente');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_calendar_booking_validation_returns_to_availability(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::BOOK_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $from = '/disponibilidad?nueva=1&fecha=2026-11-02&hora_inicio=09:00&hora_fin=09:30';
        $this->from($from)->post('/citas', ['origen' => 'disponibilidad', 'modalidad' => 'Presencial'])
            ->assertRedirect($from)->assertSessionHasErrors('id_paciente');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_registered_appointment_is_shown_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::BOOK_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointment = (object) [
            'id_cita' => 21,
            'codigo' => 'CIT-20261101080000ab',
            'inicio' => '2026-12-01T08:00:00+00:00',
            'fin' => '2026-12-01T08:30:00+00:00',
            'motivo_consulta' => null,
            'modalidad' => 'Presencial',
            'estado' => 'Programada',
            'id_paciente' => 8,
            'paciente_nombres' => 'Ana',
            'paciente_apellidos' => 'Rojas',
            'paciente_ci' => '123',
            'medico_nombres' => 'Luis',
            'medico_apellidos' => 'Soto',
            'registro_profesional' => 'MP-1',
            'especialidad_nombre' => 'Pediatría',
        ];
        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentById')->twice()->with(21)->andReturn($appointment);
        $appointments->shouldReceive('getLatestReminderForAppointment')->once()->with(21)->andReturn((object) [
            'id_recordatorio' => 9,
            'fecha_programada' => '2026-11-30T08:00:00+00:00',
            'fecha_intento' => null,
            'estado' => 'Pendiente',
            'referencia_envio' => null,
            'id_cita' => 21,
        ]);
        $appointments->shouldReceive('getNotificationPreferencesForPatient')->once()->with(8)->andReturn((object) [
            'recordatorios_habilitados' => true,
            'canal' => 'CorreoElectronico',
            'destino' => 'ana@example.test',
            'destino_verificado' => true,
        ]);
        $appointments->shouldReceive('getPatientContactEmail')->once()->with(8)->andReturn(null);
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/21')->assertOk()->assertSee('CIT-20261101080000ab')
            ->assertSee('Programada')->assertSee('Ana')->assertSee('no confirma', false)
            ->assertSee('Recordatorio por correo')->assertSee('Enviar recordatorio ahora')
            ->assertSee('sin esperar las 24 h', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_guests_are_sent_to_login_for_the_appointment_list(): void
    {
        $this->get('/citas')->assertRedirect(route('login'));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_reschedule_permission_is_denied_the_list(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_AVAILABILITY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_appointment_list_rejects_an_invalid_status_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RESCHEDULE_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/citas')->get('/citas?estado=Noasistio')
            ->assertRedirect('/citas')->assertSessionHasErrors('estado');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_session_lists_appointments_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RESCHEDULE_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAllAppointments')->once()->with(null, null, null)->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'codigo' => 'CIT-20261101080000ab',
                'inicio' => '2026-11-01T08:00:00+00:00',
                'estado' => 'Programada',
                'paciente_nombres' => 'Ana',
                'paciente_apellidos' => 'Rojas',
                'medico_nombres' => 'Luis',
                'medico_apellidos' => 'Soto',
                'especialidad_nombre' => 'Pediatría',
            ],
        ]));
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas')->assertOk()->assertSee('Citas registradas')
            ->assertSee('CIT-20261101080000ab')->assertSee('Reprogramar')->assertSee('Pediatría')
            ->assertSee('1 nov 2026')->assertSee('Todos')
            ->assertSee('appointment-cards', false)
            ->assertSee('appointment-table-wrap', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_reschedule_form_opens_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RESCHEDULE_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentById')->once()->with(21)->andReturn((object) [
            'id_cita' => 21,
            'codigo' => 'CIT-20261101080000ab',
            'inicio' => '2026-11-01T08:00:00+00:00',
            'fin' => '2026-11-01T08:30:00+00:00',
            'motivo_consulta' => null,
            'modalidad' => 'Presencial',
            'estado' => 'Confirmada',
            'id_paciente' => 8,
            'id_medico' => 4,
            'id_especialidad' => 3,
            'paciente_nombres' => 'Ana',
            'paciente_apellidos' => 'Rojas',
            'paciente_ci' => '123',
            'medico_nombres' => 'Luis',
            'medico_apellidos' => 'Soto',
            'registro_profesional' => 'MP-1',
            'especialidad_nombre' => 'Pediatría',
        ]);
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/21/reprogramar')->assertOk()->assertSee('Reprogramar CIT-20261101080000ab')
            ->assertSee('Confirmada')->assertSee('Confirmar reprogramación');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_reschedule_validation_does_not_query_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RESCHEDULE_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/citas/21/reprogramar')->post('/citas/21/reprogramar', [])
            ->assertRedirect('/citas/21/reprogramar')->assertSessionHasErrors('fecha');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_cancel_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RESCHEDULE_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/21/cancelar')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_cancel_form_opens_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CANCEL_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentById')->once()->with(21)->andReturn((object) [
            'id_cita' => 21,
            'codigo' => 'CIT-20261101080000ab',
            'inicio' => '2026-11-01T08:00:00+00:00',
            'fin' => '2026-11-01T08:30:00+00:00',
            'motivo_consulta' => null,
            'modalidad' => 'Presencial',
            'estado' => 'Programada',
            'id_medico' => 4,
            'id_especialidad' => 3,
            'paciente_nombres' => 'Ana',
            'paciente_apellidos' => 'Rojas',
            'paciente_ci' => '123',
            'medico_nombres' => 'Luis',
            'medico_apellidos' => 'Soto',
            'registro_profesional' => 'MP-1',
            'especialidad_nombre' => 'Pediatría',
        ]);
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/21/cancelar')->assertOk()->assertSee('Cancelar CIT-20261101080000ab')
            ->assertSee('Confirmar cancelación')->assertSee('No cancelar');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_guests_are_sent_to_login_for_attendance(): void
    {
        $this->get('/citas/21/asistencia')->assertRedirect(route('login'));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_attendance_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CANCEL_APPOINTMENTS]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/21/asistencia')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_attendance_form_opens_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RECORD_ATTENDANCE]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentById')->once()->with(21)->andReturn((object) [
            'id_cita' => 21,
            'codigo' => 'CIT-20261101080000ab',
            'inicio' => '2026-11-01T08:00:00+00:00',
            'fin' => '2026-11-01T08:30:00+00:00',
            'motivo_consulta' => null,
            'modalidad' => 'Presencial',
            'estado' => 'Programada',
            'fecha_hora_llegada' => null,
            'id_medico' => 4,
            'id_especialidad' => 3,
            'paciente_nombres' => 'Ana',
            'paciente_apellidos' => 'Rojas',
            'paciente_ci' => '123',
            'medico_nombres' => 'Luis',
            'medico_apellidos' => 'Soto',
            'registro_profesional' => 'MP-1',
            'especialidad_nombre' => 'Pediatría',
        ]);
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/21/asistencia')->assertOk()->assertSee('Confirmación y asistencia')
            ->assertSee('Registrar confirmación')->assertSee('Registrar llegada')
            ->assertSee('Registrar inasistencia');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_absence_requires_verified_no_show_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RECORD_ATTENDANCE]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/citas/21/asistencia')->post('/citas/21/inasistencia', [])
            ->assertRedirect('/citas/21/asistencia')->assertSessionHasErrors('verificado');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_guests_are_sent_to_login_for_the_agenda(): void
    {
        $this->get('/agenda')->assertRedirect(route('login'));
        $this->get('/citas/21/atender')->assertRedirect(route('login'));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_attend_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 3]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::RECORD_ATTENDANCE]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/agenda')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_attend_form_opens_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 3]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MARK_ATTENDED]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getPhysicianByUserId')->once()->with(17)
            ->andReturn((object) ['id_medico' => 4, 'activo' => true, 'id_usuario' => 17]);
        $appointments->shouldReceive('getAppointmentById')->once()->with(21)->andReturn((object) [
            'id_cita' => 21,
            'codigo' => 'CIT-20261101080000ab',
            'inicio' => '2026-11-01T08:00:00+00:00',
            'fin' => '2026-11-01T08:30:00+00:00',
            'motivo_consulta' => null,
            'modalidad' => 'Presencial',
            'estado' => 'Confirmada',
            'fecha_hora_llegada' => null,
            'id_medico' => 4,
            'id_especialidad' => 3,
            'paciente_nombres' => 'Ana',
            'paciente_apellidos' => 'Rojas',
            'paciente_ci' => '123',
            'medico_nombres' => 'Luis',
            'medico_apellidos' => 'Soto',
            'registro_profesional' => 'MP-1',
            'especialidad_nombre' => 'Pediatría',
        ]);
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/citas/21/atender')->assertOk()->assertSee('Marcar como atendida')
            ->assertSee('Confirmé que brindé la atención médica');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_attend_requires_verified_care_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 3]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MARK_ATTENDED]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/citas/21/atender')->post('/citas/21/atender', [])
            ->assertRedirect('/citas/21/atender')->assertSessionHasErrors('verificado');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_physician_agenda_form_opens_without_listing_appointments(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 3]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::MARK_ATTENDED]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getPhysicianByUserId')->once()->with(17)
            ->andReturn((object) ['id_medico' => 4, 'activo' => true, 'id_usuario' => 17]);
        $appointments->shouldReceive('getAppointmentsForPhysicianOnDate')->never();
        $appointments->shouldReceive('getRegisteredPhysicians')->never();
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/agenda')->assertOk()->assertSee('Agenda médica')
            ->assertSee('Seleccione la fecha')->assertDontSee('name="id_medico"', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_receptionist_agenda_form_lists_physicians_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AGENDA,
            PermissionCatalog::CONSULT_AVAILABILITY,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getPhysicianByUserId')->once()->with(17)->andReturn(null);
        $appointments->shouldReceive('getRegisteredPhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Luis', 'apellidos' => 'Soto', 'registro_profesional' => 'MP-1', 'activo' => true],
        ]));
        $appointments->shouldReceive('getAppointmentsForPhysicianOnDate')->never();
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/agenda')->assertOk()->assertSee('Agenda médica')->assertSee('Luis')
            ->assertSee('Seleccione la fecha y el médico');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_agenda_rejects_an_invalid_date_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 3]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_AGENDA]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/agenda')->get('/agenda?fecha=31-11-2026')
            ->assertRedirect('/agenda')->assertSessionHasErrors('fecha');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_physician_agenda_lists_appointments_with_mobile_cards_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 3]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_AGENDA,
            PermissionCatalog::MARK_ATTENDED,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getPhysicianByUserId')->once()->with(17)
            ->andReturn((object) ['id_medico' => 4, 'activo' => true, 'id_usuario' => 17]);
        $appointments->shouldReceive('getAppointmentsForPhysicianOnDate')->once()
            ->withArgs(function (int $physicianId, string $start, string $end, ?string $status): bool {
                return $physicianId === 4
                    && str_starts_with($start, '2026-11-12')
                    && str_starts_with($end, '2026-11-13')
                    && $status === null;
            })
            ->andReturn(collect([
                (object) [
                    'id_cita' => 21,
                    'codigo' => 'CIT-AGENDA01',
                    'inicio' => '2026-11-12T09:00:00+00:00',
                    'fin' => '2026-11-12T09:30:00+00:00',
                    'estado' => 'Programada',
                    'paciente_nombres' => 'Ana',
                    'paciente_apellidos' => 'Rojas',
                    'especialidad_nombre' => 'Pediatría',
                    'motivo_consulta' => 'Control',
                ],
            ]));
        $appointments->shouldReceive('getRegisteredPhysicians')->never();
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/agenda?fecha=2026-11-12')->assertOk()
            ->assertSee('Agenda médica')
            ->assertSee('CIT-AGENDA01')
            ->assertSee('Rojas')
            ->assertSee('09:00')
            ->assertSee('Atender')
            ->assertSee('agenda-cards', false)
            ->assertSee('agenda-table-wrap', false);
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_guests_are_sent_to_login_for_patient_history(): void
    {
        $this->get('/historial')->assertRedirect(route('login'));
        $this->get('/history')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_history_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 3]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::MARK_ATTENDED,
            PermissionCatalog::CONSULT_AGENDA,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/historial')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_patient_history_form_opens_without_listing_appointments(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_HISTORY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getAllPatients')->never();
        $patients->shouldReceive('getPatientById')->never();
        $this->app->instance(PatientRepository::class, $patients);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsForPatient')->never();
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/historial')->assertOk()->assertSee('Citas e historial del paciente')
            ->assertSee('Busque y seleccione un paciente')->assertDontSee('Diagnóstico')
            ->assertSee('El historial del portal es una consulta distinta');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_selected_patient_history_lists_without_clinical_notes(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_HISTORY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getPatientById')->once()->with(8)
            ->andReturn((object) [
                'id_paciente' => 8,
                'nombres' => 'Ana',
                'apellidos' => 'Rojas',
                'ci' => '123',
                'activo' => true,
            ]);
        $patients->shouldReceive('getAllPatients')->never();
        $this->app->instance(PatientRepository::class, $patients);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsForPatient')->once()->with(8, null)
            ->andReturn(collect([
                (object) [
                    'id_cita' => 21,
                    'codigo' => 'CIT-1',
                    'inicio' => '2026-11-01T08:00:00+00:00',
                    'fin' => '2026-11-01T08:30:00+00:00',
                    'estado' => 'Atendida',
                    'modalidad' => 'Presencial',
                    'motivo_consulta' => 'Nota clínica secreta',
                    'paciente_nombres' => 'Ana',
                    'paciente_apellidos' => 'Rojas',
                    'paciente_ci' => '123',
                    'medico_nombres' => 'Luis',
                    'medico_apellidos' => 'Soto',
                    'especialidad_nombre' => 'Pediatría',
                ],
            ]));
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/historial?id_paciente=8')->assertOk()->assertSee('Ana')
            ->assertSee('CIT-1')->assertSee('Pediatría')->assertSee('Atendida')
            ->assertSee('No muestra diagnósticos ni notas médicas')
            ->assertSee('history-appointment-cards', false)->assertSee('stack-cards', false)
            ->assertDontSee('Nota clínica secreta');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_history_rejects_an_invalid_status_without_sql(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_HISTORY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/historial')->get('/historial?estado=Noasistio')
            ->assertRedirect('/historial')->assertSessionHasErrors('estado');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_empty_patient_history_is_not_an_error(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::CONSULT_HISTORY]);
        $this->app->instance(IdentityRepository::class, $identities);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getPatientById')->once()->with(8)
            ->andReturn((object) [
                'id_paciente' => 8,
                'nombres' => 'Ana',
                'apellidos' => 'Rojas',
                'ci' => '123',
                'activo' => true,
            ]);
        $this->app->instance(PatientRepository::class, $patients);

        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsForPatient')->once()->with(8, null)
            ->andReturn(collect());
        $this->app->instance(AppointmentRepository::class, $appointments);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/historial?id_paciente=8')->assertOk()
            ->assertSee('No hay citas que coincidan con los criterios.')
            ->assertDontSee('No fue posible consultar el historial.');
        $this->assertSame([], $this->app['db']->getConnections());
    }
}
