<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use App\Modules\MedicalStaff\SpecialtyRepository;
use App\Modules\Patients\PatientRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ReportService
{
    public const TYPE_APPOINTMENTS_ON_DATE = 'citas_fecha';

    public const TYPE_APPOINTMENTS_BY_PHYSICIAN = 'citas_medico';

    public const TYPE_APPOINTMENTS_BY_SPECIALTY = 'citas_especialidad';

    public const TYPE_APPOINTMENTS_BY_STATUS = 'citas_estado';

    public const TYPE_REGISTERED_PATIENTS = 'pacientes';

    public const TYPE_PERIOD_TOTALS = 'totales_periodo';

    public const TYPE_DAILY_AGENDA = 'agenda_diaria';

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_APPOINTMENTS_ON_DATE => 'Citas programadas para una fecha',
            self::TYPE_APPOINTMENTS_BY_PHYSICIAN => 'Citas por médico',
            self::TYPE_APPOINTMENTS_BY_SPECIALTY => 'Citas por especialidad',
            self::TYPE_APPOINTMENTS_BY_STATUS => 'Citas por estado',
            self::TYPE_REGISTERED_PATIENTS => 'Pacientes registrados',
            self::TYPE_PERIOD_TOTALS => 'Atendidas, canceladas e inasistencias en un período',
            self::TYPE_DAILY_AGENDA => 'Agenda diaria de cada médico',
        ];
    }

    public function __construct(
        private AppointmentRepository $appointments,
        private PatientRepository $patients,
        private SpecialtyRepository $specialties,
    ) {}

    /**
     * @param  array{tipo?: ?string, fecha?: ?string, fecha_inicio?: ?string, fecha_fin?: ?string, id_medico?: ?int, id_especialidad?: ?int, estado?: ?string}  $input
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    public function generateAdministrativeReport(array $input, bool $generate): array
    {
        $type = $this->optionalType($input['tipo'] ?? null);
        $result = [
            'type' => $type ?? '',
            'generated' => false,
            'rows' => null,
            'groups' => null,
            'totals' => null,
            'physicians' => $this->appointments->getRegisteredPhysicians(),
            'specialties' => $this->specialties->getAllSpecialties(null),
            'fecha' => $input['fecha'] ?? '',
            'fecha_inicio' => $input['fecha_inicio'] ?? '',
            'fecha_fin' => $input['fecha_fin'] ?? '',
            'id_medico' => isset($input['id_medico']) ? (int) $input['id_medico'] : null,
            'id_especialidad' => isset($input['id_especialidad']) ? (int) $input['id_especialidad'] : null,
            'estado' => $input['estado'] ?? '',
        ];
        if (! $generate || $type === null) {
            return $result;
        }

        return match ($type) {
            self::TYPE_APPOINTMENTS_ON_DATE => $this->appointmentsOnDate($result, (string) ($input['fecha'] ?? '')),
            self::TYPE_APPOINTMENTS_BY_PHYSICIAN => $this->appointmentsByPhysician(
                $result,
                isset($input['id_medico']) ? (int) $input['id_medico'] : null,
                (string) ($input['fecha_inicio'] ?? ''),
                (string) ($input['fecha_fin'] ?? ''),
            ),
            self::TYPE_APPOINTMENTS_BY_SPECIALTY => $this->appointmentsBySpecialty(
                $result,
                isset($input['id_especialidad']) ? (int) $input['id_especialidad'] : null,
                (string) ($input['fecha_inicio'] ?? ''),
                (string) ($input['fecha_fin'] ?? ''),
            ),
            self::TYPE_APPOINTMENTS_BY_STATUS => $this->appointmentsByStatus(
                $result,
                $input['estado'] ?? null,
                (string) ($input['fecha_inicio'] ?? ''),
                (string) ($input['fecha_fin'] ?? ''),
            ),
            self::TYPE_REGISTERED_PATIENTS => $this->registeredPatients($result),
            self::TYPE_PERIOD_TOTALS => $this->periodTotals(
                $result,
                (string) ($input['fecha_inicio'] ?? ''),
                (string) ($input['fecha_fin'] ?? ''),
            ),
            self::TYPE_DAILY_AGENDA => $this->dailyAgendas($result, (string) ($input['fecha'] ?? '')),
            default => throw new InvalidReportInput,
        };
    }

    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}  $result
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    private function appointmentsOnDate(array $result, string $date): array
    {
        [$start, $end] = $this->dayRange($date);
        $rows = $this->appointments->getAppointmentsInRange($start, $end, null, null, null);
        $result['generated'] = true;
        $result['rows'] = $rows;
        $result['totals'] = $this->appointmentStatusTotals($rows);
        $result['fecha'] = $date;

        return $result;
    }

    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}  $result
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    private function appointmentsByPhysician(array $result, ?int $physicianId, string $startDate, string $endDate): array
    {
        $physician = $this->requirePhysician($physicianId);
        [$start, $end] = $this->periodRange($startDate, $endDate);
        $rows = $this->appointments->getAppointmentsInRange($start, $end, (int) $physician->id_medico, null, null);
        $result['generated'] = true;
        $result['rows'] = $rows;
        $result['totals'] = $this->appointmentStatusTotals($rows);
        $result['id_medico'] = (int) $physician->id_medico;
        $result['fecha_inicio'] = $startDate;
        $result['fecha_fin'] = $endDate;

        return $result;
    }

    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}  $result
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    private function appointmentsBySpecialty(array $result, ?int $specialtyId, string $startDate, string $endDate): array
    {
        $specialty = $this->requireSpecialty($specialtyId);
        [$start, $end] = $this->periodRange($startDate, $endDate);
        $rows = $this->appointments->getAppointmentsInRange($start, $end, null, (int) $specialty->id_especialidad, null);
        $result['generated'] = true;
        $result['rows'] = $rows;
        $result['totals'] = $this->appointmentStatusTotals($rows);
        $result['id_especialidad'] = (int) $specialty->id_especialidad;
        $result['fecha_inicio'] = $startDate;
        $result['fecha_fin'] = $endDate;

        return $result;
    }

    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}  $result
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    private function appointmentsByStatus(array $result, ?string $status, string $startDate, string $endDate): array
    {
        $normalized = $this->requireStatus($status);
        [$start, $end] = $this->periodRange($startDate, $endDate);
        $rows = $this->appointments->getAppointmentsInRange($start, $end, null, null, $normalized);
        $result['generated'] = true;
        $result['rows'] = $rows;
        $result['totals'] = $this->appointmentStatusTotals($rows);
        $result['estado'] = $normalized;
        $result['fecha_inicio'] = $startDate;
        $result['fecha_fin'] = $endDate;

        return $result;
    }

    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}  $result
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    private function registeredPatients(array $result): array
    {
        $rows = $this->patients->getAllPatients(null);
        $result['generated'] = true;
        $result['rows'] = $rows;
        $result['totals'] = [
            'pacientes' => $rows->count(),
            'activos' => $rows->filter(fn (object $patient): bool => (bool) $patient->activo)->count(),
            'inactivos' => $rows->filter(fn (object $patient): bool => ! (bool) $patient->activo)->count(),
        ];

        return $result;
    }

    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}  $result
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    private function periodTotals(array $result, string $startDate, string $endDate): array
    {
        [$start, $end] = $this->periodRange($startDate, $endDate);
        $counts = $this->appointments->getAppointmentStatusCountsInRange($start, $end);
        $totals = ['Atendida' => 0, 'Cancelada' => 0, 'Inasistencia' => 0];
        foreach ($counts as $row) {
            $status = (string) $row->estado;
            if (array_key_exists($status, $totals)) {
                $totals[$status] = (int) $row->total;
            }
        }
        $result['generated'] = true;
        $result['totals'] = $totals;
        $result['rows'] = collect();
        $result['fecha_inicio'] = $startDate;
        $result['fecha_fin'] = $endDate;

        return $result;
    }

    /**
     * @param  array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}  $result
     * @return array{type: string, generated: bool, rows: ?Collection, groups: ?Collection, totals: ?array, physicians: Collection, specialties: Collection, fecha: string, fecha_inicio: string, fecha_fin: string, id_medico: ?int, id_especialidad: ?int, estado: string}
     */
    private function dailyAgendas(array $result, string $date): array
    {
        [$start, $end] = $this->dayRange($date);
        $appointments = $this->appointments->getAppointmentsInRange($start, $end, null, null, null);
        $grouped = $result['physicians']->map(function (object $physician) use ($appointments): object {
            $rows = $appointments->where('id_medico', $physician->id_medico)->values();

            return (object) [
                'physician' => $physician,
                'appointments' => $rows,
            ];
        });
        $result['generated'] = true;
        $result['groups'] = $grouped;
        $result['rows'] = $appointments;
        $result['totals'] = array_merge($this->appointmentStatusTotals($appointments), [
            'medicos' => $grouped->count(),
        ]);
        $result['fecha'] = $date;

        return $result;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{citas: int, Confirmada: int, Programada: int, Pendientes: int, Cancelada: int, Atendida: int, Inasistencia: int}
     */
    private function appointmentStatusTotals(Collection $rows): array
    {
        $programada = $rows->where('estado', 'Programada')->count();

        return [
            'citas' => $rows->count(),
            'Confirmada' => $rows->where('estado', 'Confirmada')->count(),
            'Programada' => $programada,
            'Pendientes' => $programada,
            'Cancelada' => $rows->where('estado', 'Cancelada')->count(),
            'Atendida' => $rows->where('estado', 'Atendida')->count(),
            'Inasistencia' => $rows->where('estado', 'Inasistencia')->count(),
        ];
    }

    private function optionalType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        if (! array_key_exists($type, self::types())) {
            throw new InvalidReportInput;
        }

        return $type;
    }

    /** @return array{0: string, 1: string} */
    private function dayRange(string $date): array
    {
        $parsed = Carbon::createFromFormat('Y-m-d', $date, 'UTC');
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidReportInput;
        }

        return [
            $parsed->copy()->startOfDay()->toIso8601String(),
            $parsed->copy()->addDay()->startOfDay()->toIso8601String(),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function periodRange(string $startDate, string $endDate): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $startDate, 'UTC');
        $end = Carbon::createFromFormat('Y-m-d', $endDate, 'UTC');
        if ($start === false || $start->format('Y-m-d') !== $startDate
            || $end === false || $end->format('Y-m-d') !== $endDate) {
            throw new InvalidReportInput;
        }
        if ($start->gt($end)) {
            throw new InvalidReportInput;
        }

        return [
            $start->copy()->startOfDay()->toIso8601String(),
            $end->copy()->addDay()->startOfDay()->toIso8601String(),
        ];
    }

    private function requirePhysician(?int $physicianId): object
    {
        if ($physicianId === null || $physicianId < 1) {
            throw new InvalidReportInput;
        }
        $physician = $this->appointments->getRegisteredPhysicianById($physicianId);
        if ($physician === null) {
            throw new ReportCriterionInvalid;
        }

        return $physician;
    }

    private function requireSpecialty(?int $specialtyId): object
    {
        if ($specialtyId === null || $specialtyId < 1) {
            throw new InvalidReportInput;
        }
        $specialty = $this->specialties->getSpecialtyById($specialtyId);
        if ($specialty === null) {
            throw new ReportCriterionInvalid;
        }

        return $specialty;
    }

    private function requireStatus(?string $status): string
    {
        if ($status === null || $status === '') {
            throw new InvalidReportInput;
        }
        if (! in_array($status, ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'], true)) {
            throw new ReportCriterionInvalid;
        }

        return $status;
    }
}
