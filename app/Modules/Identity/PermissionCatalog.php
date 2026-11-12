<?php

declare(strict_types=1);

namespace App\Modules\Identity;

final class PermissionCatalog
{
    public const MANAGE_ACCOUNTS = 'usuarios.administrar';

    public const MANAGE_PATIENTS = 'pacientes.gestionar';

    public const MANAGE_SPECIALTIES = 'especialidades.gestionar';

    public const MANAGE_PHYSICIANS = 'medicos.gestionar';

    public const MANAGE_SCHEDULES = 'horarios.gestionar';

    public const CONSULT_AVAILABILITY = 'citas.consultar';

    public const BOOK_APPOINTMENTS = 'citas.programar';

    public const PAY_CONSULTATIONS = 'citas.pagar';

    public const RESCHEDULE_APPOINTMENTS = 'citas.reprogramar';

    public const CANCEL_APPOINTMENTS = 'citas.cancelar';

    public const RECORD_ATTENDANCE = 'citas.asistencia';

    public const MARK_ATTENDED = 'citas.atender';

    public const CONSULT_AGENDA = 'citas.agenda';

    public const CONSULT_HISTORY = 'citas.historial';

    public const GENERATE_REPORTS = 'reportes.generar';

    public const CONSULT_ACTIVITY_LOG = 'bitacora.consultar';

    public const UPDATE_PROFILE = 'perfil.actualizar';

    public const DECLARE_HEALTH = 'informacion.declarar';

    public const CONSULT_CLINICAL_HISTORY = 'historial.consultar';

    public const ADMINISTRATOR_ROLE = 'Administrador';

    public const RECEPTIONIST_ROLE = 'Recepcionista';

    public const PHYSICIAN_ROLE = 'Médico';

    public const PATIENT_ROLE = 'Paciente';

    /**
     * @return list<array{codigo: string, descripcion: string}>
     */
    public static function permissions(): array
    {
        return [
            ['codigo' => self::MANAGE_ACCOUNTS, 'descripcion' => 'Administrar cuentas de acceso y roles'],
            ['codigo' => self::MANAGE_PATIENTS, 'descripcion' => 'Registrar, consultar, modificar y desactivar pacientes'],
            ['codigo' => self::MANAGE_SPECIALTIES, 'descripcion' => 'Administrar el catálogo de especialidades'],
            ['codigo' => self::MANAGE_PHYSICIANS, 'descripcion' => 'Administrar médicos y sus especialidades'],
            ['codigo' => self::MANAGE_SCHEDULES, 'descripcion' => 'Configurar horarios de atención de los médicos'],
            ['codigo' => self::CONSULT_AVAILABILITY, 'descripcion' => 'Consultar médicos habilitados e intervalos disponibles'],
            ['codigo' => self::BOOK_APPOINTMENTS, 'descripcion' => 'Programar citas médicas en intervalos disponibles'],
            ['codigo' => self::PAY_CONSULTATIONS, 'descripcion' => 'Cobrar la consulta de una cita con pasarela en línea'],
            ['codigo' => self::RESCHEDULE_APPOINTMENTS, 'descripcion' => 'Reprogramar citas médicas existentes'],
            ['codigo' => self::CANCEL_APPOINTMENTS, 'descripcion' => 'Cancelar citas médicas y liberar el intervalo'],
            ['codigo' => self::RECORD_ATTENDANCE, 'descripcion' => 'Registrar confirmación, llegada e inasistencia'],
            ['codigo' => self::MARK_ATTENDED, 'descripcion' => 'Marcar una cita como atendida'],
            ['codigo' => self::CONSULT_AGENDA, 'descripcion' => 'Consultar la agenda médica por médico, fecha y estado'],
            ['codigo' => self::CONSULT_HISTORY, 'descripcion' => 'Consultar citas e historial de un paciente'],
            ['codigo' => self::GENERATE_REPORTS, 'descripcion' => 'Generar reportes administrativos de citas, pacientes y agendas'],
            ['codigo' => self::CONSULT_ACTIVITY_LOG, 'descripcion' => 'Consultar la bitácora de accesos y cambios del sistema'],
            ['codigo' => self::UPDATE_PROFILE, 'descripcion' => 'Actualizar el perfil propio y las preferencias de recordatorios'],
            ['codigo' => self::DECLARE_HEALTH, 'descripcion' => 'Consultar y actualizar la información médica declarada por el paciente'],
            ['codigo' => self::CONSULT_CLINICAL_HISTORY, 'descripcion' => 'Consultar el historial médico propio autorizado en el portal'],
        ];
    }

    /**
     * @return list<array{nombre: string, descripcion: string}>
     */
    public static function roles(): array
    {
        return [
            ['nombre' => self::ADMINISTRATOR_ROLE, 'descripcion' => 'Administra cuentas, catálogos y reportes autorizados'],
            ['nombre' => self::RECEPTIONIST_ROLE, 'descripcion' => 'Gestiona pacientes y citas según permisos'],
            ['nombre' => self::PHYSICIAN_ROLE, 'descripcion' => 'Consulta su agenda y registra la atención'],
            ['nombre' => self::PATIENT_ROLE, 'descripcion' => 'Accede al portal con las funciones autorizadas de su cuenta'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codesForRole(string $roleName): array
    {
        return match ($roleName) {
            self::ADMINISTRATOR_ROLE => [
                self::MANAGE_ACCOUNTS,
                self::MANAGE_PATIENTS,
                self::MANAGE_SPECIALTIES,
                self::MANAGE_PHYSICIANS,
                self::MANAGE_SCHEDULES,
                self::CONSULT_AVAILABILITY,
                self::BOOK_APPOINTMENTS,
                self::PAY_CONSULTATIONS,
                self::RESCHEDULE_APPOINTMENTS,
                self::CANCEL_APPOINTMENTS,
                self::RECORD_ATTENDANCE,
                self::MARK_ATTENDED,
                self::CONSULT_AGENDA,
                self::CONSULT_HISTORY,
                self::GENERATE_REPORTS,
                self::CONSULT_ACTIVITY_LOG,
            ],
            self::RECEPTIONIST_ROLE => [
                self::MANAGE_PATIENTS,
                self::CONSULT_AVAILABILITY,
                self::BOOK_APPOINTMENTS,
                self::PAY_CONSULTATIONS,
                self::RESCHEDULE_APPOINTMENTS,
                self::CANCEL_APPOINTMENTS,
                self::RECORD_ATTENDANCE,
                self::CONSULT_AGENDA,
                self::CONSULT_HISTORY,
            ],
            self::PHYSICIAN_ROLE => [
                self::MARK_ATTENDED,
                self::CONSULT_AGENDA,
            ],
            self::PATIENT_ROLE => [
                self::UPDATE_PROFILE,
                self::DECLARE_HEALTH,
                self::CONSULT_CLINICAL_HISTORY,
            ],
            default => [],
        };
    }

    public static function fingerprint(): string
    {
        $assignments = [];
        foreach (self::roles() as $role) {
            $assignments[$role['nombre']] = self::codesForRole($role['nombre']);
        }

        return hash('sha256', json_encode([
            self::roles(),
            self::permissions(),
            $assignments,
        ], JSON_THROW_ON_ERROR));
    }
}
