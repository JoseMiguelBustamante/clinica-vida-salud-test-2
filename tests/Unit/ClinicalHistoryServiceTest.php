<?php

namespace Tests\Unit;

use App\Modules\Appointments\AppointmentReminderMailer;
use App\Modules\Appointments\AppointmentReminderService;
use App\Modules\Appointments\AppointmentRepository;
use App\Modules\Appointments\ClinicalHistoryRecordUnavailable;
use App\Modules\Appointments\ClinicalHistoryService;
use App\Modules\Appointments\ClinicalHistoryUnavailable;
use App\Modules\Patients\PatientLinkRequired;
use App\Modules\Patients\PatientRepository;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class ClinicalHistoryServiceTest extends TestCase
{
    public function test_a_missing_patient_link_is_rejected_before_reads(): void
    {
        $patients = Mockery::mock(PatientRepository::class);
        $appointments = Mockery::mock(AppointmentRepository::class);
        $patients->shouldReceive('getPatientByAccount')->once()->with(17)->andReturn(null);
        $appointments->shouldReceive('getAppointmentsForPatient')->never();

        try {
            $this->service($appointments, $patients)->getOwnClinicalHistory(17);
            $this->fail('Expected PatientLinkRequired');
        } catch (PatientLinkRequired) {
        }

        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_professional_fields_are_omitted_from_authorized_records(): void
    {
        $patients = Mockery::mock(PatientRepository::class);
        $appointments = Mockery::mock(AppointmentRepository::class);
        $patients->shouldReceive('getPatientByAccount')->once()->with(17)->andReturn($this->linkedPatient());
        $appointments->shouldReceive('getAppointmentsForPatient')->once()->with(8, null)
            ->andReturn(collect([$this->storedAppointment(['diagnostico' => 'Asma', 'resumen_atencion' => 'Nota'])]));

        $result = $this->service($appointments, $patients)->getOwnClinicalHistory(17);

        $record = $result['records']->first();
        $this->assertSame('CIT-1', $record->codigo);
        $this->assertFalse(isset($record->diagnostico));
        $this->assertFalse(isset($record->resumen_atencion));
        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_another_persons_record_is_unavailable(): void
    {
        $patients = Mockery::mock(PatientRepository::class);
        $appointments = Mockery::mock(AppointmentRepository::class);
        $patients->shouldReceive('getPatientByAccount')->once()->with(17)->andReturn($this->linkedPatient());
        $appointments->shouldReceive('getAppointmentById')->once()->with(21)
            ->andReturn($this->storedAppointment(['id_paciente' => 99, 'diagnostico' => 'Asma']));

        try {
            $this->service($appointments, $patients)->getOwnClinicalHistoryRecord(17, 21);
            $this->fail('Expected ClinicalHistoryRecordUnavailable');
        } catch (ClinicalHistoryRecordUnavailable) {
        }

        $this->assertSame([], $this->app['db']->getConnections());
    }

    public function test_an_authorized_detail_keeps_the_declared_reason_and_drops_notes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30T12:00:00Z'));
        $patients = Mockery::mock(PatientRepository::class);
        $appointments = Mockery::mock(AppointmentRepository::class);
        $patients->shouldReceive('getPatientByAccount')->once()->with(17)->andReturn($this->linkedPatient());
        $appointments->shouldReceive('getAppointmentById')->twice()->with(21)
            ->andReturn($this->storedAppointment([
                'motivo_consulta' => 'Dolor de cabeza',
                'diagnostico' => 'Migraña',
                'resumen_atencion' => 'Nota profesional',
            ]));
        $appointments->shouldReceive('getLatestReminderForAppointment')->once()->with(21)->andReturn(null);
        $appointments->shouldReceive('getNotificationPreferencesForPatient')->once()->with(8)->andReturn(null);
        $appointments->shouldReceive('getPatientContactEmail')->once()->with(8)->andReturn(null);

        $result = $this->service($appointments, $patients)->getOwnClinicalHistoryRecord(17, 21);

        $this->assertSame('Dolor de cabeza', $result['record']->motivo_consulta);
        $this->assertFalse(isset($result['record']->diagnostico));
        $this->assertFalse(isset($result['record']->resumen_atencion));
        $this->assertSame('No elegible', $result['reminder']['status_label']);
        $this->assertSame([], $this->app['db']->getConnections());
        Carbon::setTestNow();
    }

    public function test_a_disabled_portal_rejects_clinical_history(): void
    {
        config(['identity.patient_portal' => false]);
        $patients = Mockery::mock(PatientRepository::class);
        $appointments = Mockery::mock(AppointmentRepository::class);
        $patients->shouldReceive('getPatientByAccount')->never();
        $appointments->shouldReceive('getAppointmentsForPatient')->never();

        try {
            $this->service($appointments, $patients)->getOwnClinicalHistory(17);
            $this->fail('Expected ClinicalHistoryUnavailable');
        } catch (ClinicalHistoryUnavailable) {
        }

        $this->assertSame([], $this->app['db']->getConnections());
    }

    private function service(
        AppointmentRepository $appointments,
        PatientRepository $patients,
        ?AppointmentReminderService $reminders = null,
    ): ClinicalHistoryService {
        return new ClinicalHistoryService(
            $appointments,
            $patients,
            $reminders ?? new AppointmentReminderService(
                $appointments,
                Mockery::mock(AppointmentReminderMailer::class),
            ),
        );
    }

    private function linkedPatient(): object
    {
        return (object) [
            'id_paciente' => 8,
            'nombres' => 'Ana',
            'apellidos' => 'Rojas',
            'id_usuario' => 17,
            'activo' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storedAppointment(array $overrides = []): object
    {
        return (object) array_merge([
            'id_cita' => 21,
            'codigo' => 'CIT-1',
            'inicio' => '2026-11-01T08:00:00+00:00',
            'fin' => '2026-11-01T08:30:00+00:00',
            'estado' => 'Atendida',
            'modalidad' => 'Presencial',
            'motivo_consulta' => null,
            'motivo_cancelacion' => null,
            'id_paciente' => 8,
            'medico_nombres' => 'Luis',
            'medico_apellidos' => 'Soto',
            'registro_profesional' => 'MP-1',
            'especialidad_nombre' => 'Pediatría',
        ], $overrides);
    }
}
