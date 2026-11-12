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
use RuntimeException;
use Tests\TestCase;

class ReportScreenTest extends TestCase
{
    public function test_guests_are_sent_to_login_for_reports(): void
    {
        $this->get('/reportes')->assertRedirect(route('login'));
        $this->get('/reports')->assertNotFound();
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_session_without_report_permission_is_denied(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 2]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([
            PermissionCatalog::CONSULT_HISTORY,
            PermissionCatalog::CONSULT_AGENDA,
        ]);
        $this->app->instance(IdentityRepository::class, $identities);

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes')->assertForbidden()->assertSee('No tiene permiso para realizar esta operación.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_the_report_form_opens_without_listing_results(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->never();
        $appointments->shouldReceive('getAppointmentStatusCountsInRange')->never();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes')->assertOk()->assertSee('Reportes administrativos')
            ->assertSee('Citas programadas para una fecha')
            ->assertSee('Generar reporte')
            ->assertSee('Seleccione un tipo de reporte')
            ->assertDontSee('Criterios aplicados');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_date_report_lists_without_clinical_notes(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()
            ->withArgs(function (string $start, string $end, ?int $physicianId, ?int $specialtyId, ?string $status): bool {
                return str_starts_with($start, '2026-11-01')
                    && str_starts_with($end, '2026-11-02')
                    && $physicianId === null
                    && $specialtyId === null
                    && $status === null;
            })
            ->andReturn(collect([
                (object) [
                    'id_cita' => 21,
                    'codigo' => 'CIT-1',
                    'inicio' => '2026-11-01T08:00:00+00:00',
                    'fin' => '2026-11-01T08:30:00+00:00',
                    'estado' => 'Programada',
                    'id_medico' => 4,
                    'motivo_consulta' => 'Nota clínica secreta',
                    'paciente_nombres' => 'Ana',
                    'paciente_apellidos' => 'Rojas',
                    'medico_nombres' => 'Luis',
                    'medico_apellidos' => 'Soto',
                    'especialidad_nombre' => 'Pediatría',
                ],
            ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->assertOk()->assertSee('Criterios aplicados')->assertSee('CIT-1')
            ->assertSee('Total de citas')->assertSee('Resultado del reporte')
            ->assertSee('Exportar')->assertSee('PDF')->assertSee('CSV')->assertSee('Excel')
            ->assertSee('Pediatría')
            ->assertDontSee('Nota clínica secreta');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_csv_export_downloads_without_clinical_notes(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()->andReturn(collect([
            (object) [
                'id_cita' => 21,
                'codigo' => 'CIT-1',
                'inicio' => '2026-11-01T08:00:00+00:00',
                'fin' => '2026-11-01T08:30:00+00:00',
                'estado' => 'Programada',
                'id_medico' => 4,
                'motivo_consulta' => 'Nota clínica secreta',
                'paciente_nombres' => 'Ana',
                'paciente_apellidos' => 'Rojas',
                'medico_nombres' => 'Luis',
                'medico_apellidos' => 'Soto',
                'especialidad_nombre' => 'Pediatría',
            ],
        ]));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $response = $this->get('/reportes/exportar?tipo=citas_fecha&fecha=2026-11-01&formato=csv');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('CIT-1', $response->streamedContent());
        $this->assertStringNotContainsString('Nota clínica secreta', $response->streamedContent());
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_empty_report_results_are_not_an_error(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()
            ->andReturn(collect());

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->assertOk()->assertSee('No hay datos para la consulta.')
            ->assertSee('Total de citas')
            ->assertSee('Pendientes')
            ->assertDontSee('No fue posible generar el reporte.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_a_processing_failure_is_not_shown_as_empty_totals(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getAppointmentsInRange')->once()
            ->andThrow(new RuntimeException('unavailable'));

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_fecha&fecha=2026-11-01&generar=1')
            ->assertOk()->assertSee('No fue posible generar el reporte.')
            ->assertDontSee('No hay datos para la consulta.')
            ->assertDontSee('Total de citas: 0');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_reports_reject_a_missing_date_without_sql(): void
    {
        $this->authorizeReports();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/reportes')->get('/reportes?tipo=citas_fecha&generar=1')
            ->assertRedirect('/reportes')->assertSessionHasErrors('fecha');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_reports_reject_a_reversed_period_without_sql(): void
    {
        $this->authorizeReports();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->from('/reportes')->get('/reportes?tipo=totales_periodo&fecha_inicio=2026-11-02&fecha_fin=2026-11-01&generar=1')
            ->assertRedirect('/reportes')->assertSessionHasErrors('fecha_inicio');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_unknown_physician_is_rejected_before_listing(): void
    {
        $this->authorizeReports();
        $this->mockCatalogLookups();
        $appointments = $this->app->make(AppointmentRepository::class);
        $appointments->shouldReceive('getRegisteredPhysicianById')->once()->with(9)->andReturn(null);
        $appointments->shouldReceive('getAppointmentsInRange')->never();

        $this->withSession([Auth::guard('web')->getName() => 17]);
        $this->get('/reportes?tipo=citas_medico&id_medico=9&fecha_inicio=2026-11-01&fecha_fin=2026-11-01&generar=1')
            ->assertOk()->assertSee('El médico, la especialidad o el estado seleccionado no es válido para este reporte.');
        $this->assertSame([], $this->app['db']->getConnections());
    }

    private function authorizeReports(): void
    {
        $user = (new User)->forceFill(['id_usuario' => 17, 'activo' => true, 'id_rol' => 1]);
        $identities = Mockery::mock(IdentityRepository::class);
        $identities->shouldReceive('active')->once()->with(17)->andReturn($user);
        $identities->shouldReceive('permissionCodes')->once()->with($user)->andReturn([PermissionCatalog::GENERATE_REPORTS]);
        $this->app->instance(IdentityRepository::class, $identities);
    }

    private function mockCatalogLookups(): void
    {
        $appointments = Mockery::mock(AppointmentRepository::class);
        $appointments->shouldReceive('getRegisteredPhysicians')->once()->andReturn(collect([
            (object) ['id_medico' => 4, 'nombres' => 'Luis', 'apellidos' => 'Soto', 'registro_profesional' => 'MP-1', 'activo' => true],
        ]));
        $this->app->instance(AppointmentRepository::class, $appointments);

        $specialties = Mockery::mock(SpecialtyRepository::class);
        $specialties->shouldReceive('getAllSpecialties')->once()->with(null)->andReturn(collect([
            (object) ['id_especialidad' => 3, 'nombre' => 'Pediatría', 'activo' => true],
        ]));
        $this->app->instance(SpecialtyRepository::class, $specialties);

        $patients = Mockery::mock(PatientRepository::class);
        $patients->shouldReceive('getAllPatients')->never();
        $this->app->instance(PatientRepository::class, $patients);
    }
}
