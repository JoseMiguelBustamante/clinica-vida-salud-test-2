<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\IdentityRepository;
use App\Modules\MedicalStaff\DuplicateProfessionalLicense;
use App\Modules\MedicalStaff\DuplicateSpecialtyName;
use App\Modules\MedicalStaff\PhysicianService;
use App\Modules\MedicalStaff\SpecialtyService;
use App\Modules\Patients\DuplicatePatientDocument;
use App\Modules\Patients\PatientService;
use App\Modules\Schedules\ScheduleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('clinic:populate-clinic-data {--force : Write operational rows without confirmation}')]
#[Description('Load realistic clinic specialties, physicians, schedules, patients and 2026 appointments')]
class PopulateClinicOperationalData extends Command
{
    protected $help = <<<'HELP'
        Requires an already migrated PostgreSQL database and an existing active Administrador.
        Creates missing specialties, physicians (MP- licenses), Mon–Sat 08:00–18:00 hours for
        every active bookable physician (so November availability works), patients with
        ordinary Bolivian-style CI values (~40 patients), about ninety year-to-date 2026
        appointments (CVS- codes), plus a dense November detail batch (CVS2- codes across
        Mon–Sat through late November, several slots per physician) so status/list
        reports for this month have many Atendida, Cancelada and Inasistencia rows.
        Idempotent: existing CI, professional license or appointment code rows are
        skipped. This is not db:seed, not demo data, and does not create login accounts or
        passwords. Confirmation is required unless --force is passed. --no-interaction
        without --force refuses before database access.
        HELP;

    public function __construct(
        private IdentityRepository $identities,
        private SpecialtyService $specialties,
        private PhysicianService $physicians,
        private PatientService $patients,
        private ScheduleService $schedules,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->input->isInteractive()) {
            $this->error('Confirmation is required; no changes made. Re-run with --force after reviewing.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Load operational clinic specialties, physicians, schedules, patients and 2026 appointments?')) {
            $this->error('Confirmation is required; no changes made.');

            return self::FAILURE;
        }

        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                $this->error('Unable to load clinic data; stop and contact the operator.');

                return self::FAILURE;
            }

            if ($this->activeAdministratorId() === null) {
                $this->error('An active administrator is required; no changes made.');

                return self::FAILURE;
            }

            $specialtyIds = $this->ensureSpecialties();
            $physicianIds = $this->ensurePhysicians($specialtyIds);
            $scheduleCount = $this->schedules->ensureMissingWeekdaySchedulesForActivePhysicians();
            foreach ($physicianIds as $physicianId) {
                $this->ensureWeekdayHours($physicianId);
            }
            $patientIds = $this->ensurePatients();
            $appointmentCount = $this->ensureAppointments($physicianIds, $specialtyIds, $patientIds);
            $novemberCount = $this->ensureNovemberDetailAppointments($physicianIds, $specialtyIds, $patientIds);

            $this->info('Operational clinic data is available.');
            $this->line('Physicians with weekday hours: '.count($physicianIds));
            $this->line('Schedules created in ensure pass: '.$scheduleCount);
            $this->line('Patients ensured: '.count($patientIds));
            $this->line('Appointments inserted this run: '.($appointmentCount + $novemberCount));
            $this->line('November detail appointments inserted: '.$novemberCount);
        } catch (Throwable $exception) {
            Log::error('[PopulateClinicOperationalData] '.$exception::class);
            $this->error('Unable to load clinic data; stop and contact the operator.');
            $this->warn($exception::class);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function activeAdministratorId(): ?int
    {
        $admin = $this->identities->getAllAccounts(null)->first(
            fn (object $account): bool => $account->rol_nombre === 'Administrador' && (bool) $account->activo,
        );

        return $admin === null ? null : (int) $admin->id_usuario;
    }

    /**
     * @return array<string, int>
     */
    private function ensureSpecialties(): array
    {
        $catalog = [
            'Medicina general' => 'Atención primaria y control ambulatorio.',
            'Pediatría' => 'Atención de niñas, niños y adolescentes.',
            'Ginecología' => 'Atención de salud ginecológica.',
            'Cardiología' => 'Atención del corazón y vasos sanguíneos.',
        ];
        $ids = [];
        foreach ($catalog as $name => $description) {
            try {
                $id = $this->specialties->createSpecialty($name, $description, true);
            } catch (DuplicateSpecialtyName) {
                $existing = $this->specialties->getAllSpecialties($name)->first(
                    fn (object $row): bool => mb_strtolower((string) $row->nombre) === mb_strtolower($name),
                );
                if ($existing === null) {
                    throw new DuplicateSpecialtyName;
                }
                $id = (int) $existing->id_especialidad;
            }
            $ids[$name] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $specialtyIds
     * @return list<int>
     */
    private function ensurePhysicians(array $specialtyIds): array
    {
        $rows = [
            ['registro_profesional' => 'MP-4821', 'nombres' => 'Carla', 'apellidos' => 'Mendoza Quispe', 'telefono' => '71234001', 'correo_contacto' => 'carla.mendoza@clinicavidasalud.bo', 'especialidad' => 'Medicina general'],
            ['registro_profesional' => 'MP-5178', 'nombres' => 'Rodrigo', 'apellidos' => 'Mamani Flores', 'telefono' => '71234002', 'correo_contacto' => 'rodrigo.mamani@clinicavidasalud.bo', 'especialidad' => 'Pediatría'],
            ['registro_profesional' => 'MP-6034', 'nombres' => 'Valeria', 'apellidos' => 'Choque Apaza', 'telefono' => '71234003', 'correo_contacto' => 'valeria.choque@clinicavidasalud.bo', 'especialidad' => 'Ginecología'],
            ['registro_profesional' => 'MP-6290', 'nombres' => 'Andrés', 'apellidos' => 'Peña Salazar', 'telefono' => '71234004', 'correo_contacto' => 'andres.pena@clinicavidasalud.bo', 'especialidad' => 'Cardiología'],
        ];

        $ids = [];
        foreach ($rows as $row) {
            $specialtyId = $specialtyIds[$row['especialidad']];
            try {
                $id = $this->physicians->createPhysician([
                    'registro_profesional' => $row['registro_profesional'],
                    'nombres' => $row['nombres'],
                    'apellidos' => $row['apellidos'],
                    'telefono' => $row['telefono'],
                    'correo_contacto' => $row['correo_contacto'],
                    'activo' => true,
                    'id_usuario' => null,
                    'especialidades' => [$specialtyId],
                ]);
            } catch (DuplicateProfessionalLicense) {
                $existing = $this->physicians->getAllPhysicians($row['registro_profesional'])->first(
                    fn (object $physician): bool => (string) $physician->registro_profesional === $row['registro_profesional'],
                );
                if ($existing === null) {
                    throw new DuplicateProfessionalLicense;
                }
                $id = (int) $existing->id_medico;
            }
            $ids[] = (int) $id;
        }

        return $ids;
    }

    private function ensureWeekdayHours(int $physicianId): void
    {
        $existingDays = $this->schedules->getAllSchedules(null)
            ->where('id_medico', $physicianId)
            ->where('activo', true)
            ->pluck('dia_semana');
        foreach (['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'] as $day) {
            if ($existingDays->contains($day)) {
                continue;
            }
            try {
                $this->schedules->createSchedule([
                    'id_medico' => $physicianId,
                    'dia_semana' => $day,
                    'hora_inicio' => '08:00',
                    'hora_fin' => '18:00',
                    'duracion_cita_minutos' => 30,
                    'activo' => true,
                ]);
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * @return list<int>
     */
    private function ensurePatients(): array
    {
        $rows = [
            ['ci' => '7845123', 'nombres' => 'Marisol', 'apellidos' => 'Gutiérrez Loza', 'fecha_nacimiento' => '1988-03-12', 'sexo' => 'Femenino', 'telefono' => '71234567', 'correo_contacto' => 'marisol.gutierrez@correo.bo', 'direccion' => 'Av. Arce 1201, La Paz'],
            ['ci' => '8912345', 'nombres' => 'Javier', 'apellidos' => 'Torrez Nina', 'fecha_nacimiento' => '1979-11-04', 'sexo' => 'Masculino', 'telefono' => '72345678', 'correo_contacto' => 'javier.torrez@correo.bo', 'direccion' => 'Calle Sagarnaga 45, La Paz'],
            ['ci' => '6754321', 'nombres' => 'Patricia', 'apellidos' => 'Aguilar Rojas', 'fecha_nacimiento' => '1994-07-21', 'sexo' => 'Femenino', 'telefono' => '73456789', 'correo_contacto' => 'patricia.aguilar@correo.bo', 'direccion' => 'Av. Busch 890, La Paz'],
            ['ci' => '9123456', 'nombres' => 'Luis Fernando', 'apellidos' => 'Calle Vargas', 'fecha_nacimiento' => '1969-01-30', 'sexo' => 'Masculino', 'telefono' => '74567890', 'correo_contacto' => 'luis.calle@correo.bo', 'direccion' => 'Zona Sur, Calle 21, La Paz'],
            ['ci' => '8345671', 'nombres' => 'Rosa', 'apellidos' => 'Flores Mamani', 'fecha_nacimiento' => '2001-09-08', 'sexo' => 'Femenino', 'telefono' => '75678901', 'correo_contacto' => 'rosa.flores@correo.bo', 'direccion' => 'Villa Fátima, Av. Las Américas 310, La Paz'],
            ['ci' => '7567892', 'nombres' => 'Pablo', 'apellidos' => 'Choque Apaza', 'fecha_nacimiento' => '1982-05-16', 'sexo' => 'Masculino', 'telefono' => '76789012', 'correo_contacto' => 'pablo.choque@correo.bo', 'direccion' => 'El Alto, Av. Juan Pablo II 1500'],
            ['ci' => '6987453', 'nombres' => 'Nadia', 'apellidos' => 'Rojas Quispe', 'fecha_nacimiento' => '1990-12-02', 'sexo' => 'Femenino', 'telefono' => '77890123', 'correo_contacto' => 'nadia.rojas@correo.bo', 'direccion' => 'Sopocachi, Av. 6 de Agosto 2200, La Paz'],
            ['ci' => '8234567', 'nombres' => 'Miguel', 'apellidos' => 'Condori Silva', 'fecha_nacimiento' => '1964-08-19', 'sexo' => 'Masculino', 'telefono' => '78901234', 'correo_contacto' => 'miguel.condori@correo.bo', 'direccion' => 'Miraflores, Plaza Villarroel 12, La Paz'],
            ['ci' => '7456123', 'nombres' => 'Andrea', 'apellidos' => 'Salazar Pinto', 'fecha_nacimiento' => '1996-04-14', 'sexo' => 'Femenino', 'telefono' => '70112233', 'correo_contacto' => 'andrea.salazar@correo.bo', 'direccion' => 'Calacoto, Calle 15, La Paz'],
            ['ci' => '8012345', 'nombres' => 'Hernán', 'apellidos' => 'Aliaga Cruz', 'fecha_nacimiento' => '1971-10-09', 'sexo' => 'Masculino', 'telefono' => '70223344', 'correo_contacto' => 'hernan.aliaga@correo.bo', 'direccion' => 'San Pedro, Calle Illampu 88, La Paz'],
            ['ci' => '8678901', 'nombres' => 'Verónica', 'apellidos' => 'Llanos Peña', 'fecha_nacimiento' => '1985-06-27', 'sexo' => 'Femenino', 'telefono' => '70334455', 'correo_contacto' => 'veronica.llanos@correo.bo', 'direccion' => 'Obrajes, Av. Hernando Siles 450, La Paz'],
            ['ci' => '7789012', 'nombres' => 'Carlos', 'apellidos' => 'Huanca Tapia', 'fecha_nacimiento' => '1992-02-18', 'sexo' => 'Masculino', 'telefono' => '70445566', 'correo_contacto' => 'carlos.huanca@correo.bo', 'direccion' => 'Max Paredes 1220, La Paz'],
            ['ci' => '8890123', 'nombres' => 'Daniela', 'apellidos' => 'Mercado Soliz', 'fecha_nacimiento' => '1987-12-05', 'sexo' => 'Femenino', 'telefono' => '70556677', 'correo_contacto' => 'daniela.mercado@correo.bo', 'direccion' => 'Achumani, Calle 28, La Paz'],
            ['ci' => '7001234', 'nombres' => 'Óscar', 'apellidos' => 'Paredes Lima', 'fecha_nacimiento' => '1960-03-22', 'sexo' => 'Masculino', 'telefono' => '70667788', 'correo_contacto' => 'oscar.paredes@correo.bo', 'direccion' => 'Villa Copacabana, La Paz'],
            ['ci' => '8112345', 'nombres' => 'Gabriela', 'apellidos' => 'Nina Quisbert', 'fecha_nacimiento' => '1998-08-11', 'sexo' => 'Femenino', 'telefono' => '70778899', 'correo_contacto' => 'gabriela.nina@correo.bo', 'direccion' => 'Villa Armonía, El Alto'],
            ['ci' => '7223456', 'nombres' => 'Ricardo', 'apellidos' => 'Zelada Ortiz', 'fecha_nacimiento' => '1977-01-07', 'sexo' => 'Masculino', 'telefono' => '70889900', 'correo_contacto' => 'ricardo.zelada@correo.bo', 'direccion' => 'Tembladerani, La Paz'],
            ['ci' => '8334567', 'nombres' => 'Silvia', 'apellidos' => 'Arce Ballivián', 'fecha_nacimiento' => '1983-09-29', 'sexo' => 'Femenino', 'telefono' => '70990011', 'correo_contacto' => 'silvia.arce@correo.bo', 'direccion' => 'Irpavi, Calle 1, La Paz'],
            ['ci' => '8445678', 'nombres' => 'Fernando', 'apellidos' => 'Quispe Yujra', 'fecha_nacimiento' => '1991-05-03', 'sexo' => 'Masculino', 'telefono' => '71001122', 'correo_contacto' => 'fernando.quispe@correo.bo', 'direccion' => 'Senkata, El Alto'],
            ['ci' => '8556789', 'nombres' => 'Mónica', 'apellidos' => 'Delgado Ríos', 'fecha_nacimiento' => '1974-11-16', 'sexo' => 'Femenino', 'telefono' => '71112233', 'correo_contacto' => 'monica.delgado@correo.bo', 'direccion' => 'Alto Obrajes, La Paz'],
            ['ci' => '8667890', 'nombres' => 'Mauricio', 'apellidos' => 'Vargas Choque', 'fecha_nacimiento' => '1986-07-25', 'sexo' => 'Masculino', 'telefono' => '71223344', 'correo_contacto' => 'mauricio.vargas@correo.bo', 'direccion' => 'Pampahasi, La Paz'],
            ['ci' => '8778901', 'nombres' => 'Elena', 'apellidos' => 'Paredes Quispe', 'fecha_nacimiento' => '1993-04-02', 'sexo' => 'Femenino', 'telefono' => '71334455', 'correo_contacto' => 'elena.paredes@correo.bo', 'direccion' => 'Sopocachi, Calle Belisario 410, La Paz'],
            ['ci' => '8889012', 'nombres' => 'Hugo', 'apellidos' => 'Mamani Cornejo', 'fecha_nacimiento' => '1968-09-14', 'sexo' => 'Masculino', 'telefono' => '71445566', 'correo_contacto' => 'hugo.mamani@correo.bo', 'direccion' => 'Villa Adela, El Alto'],
            ['ci' => '8990123', 'nombres' => 'Claudia', 'apellidos' => 'Rivera Soto', 'fecha_nacimiento' => '1989-12-28', 'sexo' => 'Femenino', 'telefono' => '71556677', 'correo_contacto' => 'claudia.rivera@correo.bo', 'direccion' => 'Calacoto, Calle 12, La Paz'],
            ['ci' => '8101234', 'nombres' => 'Diego', 'apellidos' => 'Flores Aguilar', 'fecha_nacimiento' => '1975-06-06', 'sexo' => 'Masculino', 'telefono' => '71667788', 'correo_contacto' => 'diego.flores@correo.bo', 'direccion' => 'Miraflores, Av. Saavedra 300, La Paz'],
            ['ci' => '8212345', 'nombres' => 'Paola', 'apellidos' => 'Choque Lima', 'fecha_nacimiento' => '2000-01-19', 'sexo' => 'Femenino', 'telefono' => '71778899', 'correo_contacto' => 'paola.choque@correo.bo', 'direccion' => 'Achumani, Calle 20, La Paz'],
            ['ci' => '8323456', 'nombres' => 'Ramiro', 'apellidos' => 'Salazar Nina', 'fecha_nacimiento' => '1981-08-23', 'sexo' => 'Masculino', 'telefono' => '71889900', 'correo_contacto' => 'ramiro.salazar@correo.bo', 'direccion' => 'San Miguel, La Paz'],
            ['ci' => '8434567', 'nombres' => 'Jimena', 'apellidos' => 'Torrez Apaza', 'fecha_nacimiento' => '1997-03-11', 'sexo' => 'Femenino', 'telefono' => '71990011', 'correo_contacto' => 'jimena.torrez@correo.bo', 'direccion' => 'Villa Copacabana, La Paz'],
            ['ci' => '8545678', 'nombres' => 'Álvaro', 'apellidos' => 'Gutiérrez Peña', 'fecha_nacimiento' => '1963-11-30', 'sexo' => 'Masculino', 'telefono' => '72001122', 'correo_contacto' => 'alvaro.gutierrez@correo.bo', 'direccion' => 'Obrajes, Calle 10, La Paz'],
            ['ci' => '8656789', 'nombres' => 'Natalia', 'apellidos' => 'Quisbert Rojas', 'fecha_nacimiento' => '1984-05-17', 'sexo' => 'Femenino', 'telefono' => '72112233', 'correo_contacto' => 'natalia.quisbert@correo.bo', 'direccion' => 'El Alto, Ciudad Satélite'],
            ['ci' => '8767890', 'nombres' => 'Bruno', 'apellidos' => 'Arce Mamani', 'fecha_nacimiento' => '1995-10-08', 'sexo' => 'Masculino', 'telefono' => '72223344', 'correo_contacto' => 'bruno.arce@correo.bo', 'direccion' => 'Irpavi, Calle 5, La Paz'],
            ['ci' => '8878901', 'nombres' => 'Lorena', 'apellidos' => 'Pinto Huanca', 'fecha_nacimiento' => '1972-02-25', 'sexo' => 'Femenino', 'telefono' => '72334455', 'correo_contacto' => 'lorena.pinto@correo.bo', 'direccion' => 'Max Paredes 980, La Paz'],
            ['ci' => '8989012', 'nombres' => 'Iván', 'apellidos' => 'Cruz Delgado', 'fecha_nacimiento' => '1988-07-01', 'sexo' => 'Masculino', 'telefono' => '72445566', 'correo_contacto' => 'ivan.cruz@correo.bo', 'direccion' => 'Villa Fátima, La Paz'],
            ['ci' => '8090123', 'nombres' => 'Camila', 'apellidos' => 'Yujra Mercado', 'fecha_nacimiento' => '1999-09-09', 'sexo' => 'Femenino', 'telefono' => '72556677', 'correo_contacto' => 'camila.yujra@correo.bo', 'direccion' => 'Alto Obrajes, La Paz'],
            ['ci' => '8201234', 'nombres' => 'Esteban', 'apellidos' => 'Loza Vargas', 'fecha_nacimiento' => '1978-12-21', 'sexo' => 'Masculino', 'telefono' => '72667788', 'correo_contacto' => 'esteban.loza@correo.bo', 'direccion' => 'Tembladerani, La Paz'],
            ['ci' => '8312345', 'nombres' => 'Rebeca', 'apellidos' => 'Nina Zelada', 'fecha_nacimiento' => '1991-04-13', 'sexo' => 'Femenino', 'telefono' => '72778899', 'correo_contacto' => 'rebeca.nina@correo.bo', 'direccion' => 'Pampahasi Alto, La Paz'],
            ['ci' => '8423456', 'nombres' => 'Martín', 'apellidos' => 'Ballivián Choque', 'fecha_nacimiento' => '1966-08-04', 'sexo' => 'Masculino', 'telefono' => '72889900', 'correo_contacto' => 'martin.ballivian@correo.bo', 'direccion' => 'Zona Sur, Calle 9, La Paz'],
            ['ci' => '8534567', 'nombres' => 'Sofía', 'apellidos' => 'Aliaga Flores', 'fecha_nacimiento' => '2002-06-15', 'sexo' => 'Femenino', 'telefono' => '72990011', 'correo_contacto' => 'sofia.aliaga@correo.bo', 'direccion' => 'Villa Armonía, El Alto'],
            ['ci' => '8645678', 'nombres' => 'Tomás', 'apellidos' => 'Ríos Condori', 'fecha_nacimiento' => '1980-01-27', 'sexo' => 'Masculino', 'telefono' => '73001122', 'correo_contacto' => 'tomas.rios@correo.bo', 'direccion' => 'Senkata, El Alto'],
            ['ci' => '8756789', 'nombres' => 'Valentina', 'apellidos' => 'Ortiz Llanos', 'fecha_nacimiento' => '1994-11-03', 'sexo' => 'Femenino', 'telefono' => '73112233', 'correo_contacto' => 'valentina.ortiz@correo.bo', 'direccion' => 'San Pedro, Calle Yanacachi 55, La Paz'],
        ];

        $ids = [];
        foreach ($rows as $row) {
            try {
                $id = $this->patients->createPatient($row);
            } catch (DuplicatePatientDocument) {
                $existing = $this->patients->getAllPatients($row['ci'])->first(
                    fn (object $patient): bool => (string) $patient->ci === $row['ci'],
                );
                if ($existing === null) {
                    throw new DuplicatePatientDocument;
                }
                $id = (int) $existing->id_paciente;
            }
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $physicianIds
     * @param  array<string, int>  $specialtyIds
     * @param  list<int>  $patientIds
     */
    private function ensureAppointments(array $physicianIds, array $specialtyIds, array $patientIds): int
    {
        if ($physicianIds === [] || $patientIds === []) {
            return 0;
        }

        $specialtyByPhysician = [
            $physicianIds[0] => $specialtyIds['Medicina general'],
            $physicianIds[1] => $specialtyIds['Pediatría'],
            $physicianIds[2] => $specialtyIds['Ginecología'],
            $physicianIds[3] => $specialtyIds['Cardiología'],
        ];

        $statuses = [];
        for ($i = 0; $i < 35; $i++) {
            $statuses[] = 'Atendida';
        }
        for ($i = 0; $i < 15; $i++) {
            $statuses[] = 'Cancelada';
        }
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = 'Inasistencia';
        }
        for ($i = 0; $i < 20; $i++) {
            $statuses[] = 'Confirmada';
        }
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = 'Programada';
        }

        $motivos = [
            'Control de presión arterial',
            'Dolor de cabeza recurrente',
            'Control pediátrico',
            'Consulta ginecológica de rutina',
            'Dolor precordial leve',
            'Control de diabetes',
            'Resfriado prolongado',
            'Control postoperatorio',
            'Chequeo anual',
            'Seguimiento de tratamiento',
        ];

        $inserted = 0;
        $day = Carbon::create(2026, 1, 5, 0, 0, 0, 'UTC');
        $end = Carbon::create(2026, 11, 13, 0, 0, 0, 'UTC');
        $index = 0;
        $hourCursor = 8;

        while ($day->lte($end) && $index < count($statuses)) {
            if ($day->isoWeekday() === 7) {
                $day->addDay();

                continue;
            }

            // Keep most of November mornings free for booking tests.
            if ((int) $day->month === 11 && $hourCursor < 12 && $index % 3 !== 0) {
                $hourCursor++;
                if ($hourCursor > 16) {
                    $hourCursor = 8;
                    $day->addDay();
                }

                continue;
            }

            $physicianId = $physicianIds[$index % count($physicianIds)];
            $patientId = $patientIds[$index % count($patientIds)];
            $specialtyId = $specialtyByPhysician[$physicianId];
            $startHour = 8 + ($index % 9);
            $start = $day->copy()->setTime($startHour, $index % 2 === 0 ? 0 : 30, 0);
            $fin = $start->copy()->addMinutes(30);
            $code = sprintf('CVS-%s-%02d', $start->format('ymd'), ($index % 90) + 1);
            $estado = $statuses[$index];

            if (DB::table('cita_medica')->where('codigo', $code)->exists()) {
                $index++;

                continue;
            }

            $overlap = DB::table('cita_medica')
                ->where('id_medico', $physicianId)
                ->where('inicio', '<', $fin->toIso8601String())
                ->where('fin', '>', $start->toIso8601String())
                ->whereNotIn('estado', ['Cancelada'])
                ->exists();
            if ($overlap) {
                $index++;
                $hourCursor++;
                if ($hourCursor > 16) {
                    $hourCursor = 8;
                    $day->addDay();
                }

                continue;
            }

            $createdAt = $start->copy()->subDays(2)->toIso8601String();
            $values = [
                'codigo' => $code,
                'inicio' => $start->toIso8601String(),
                'fin' => $fin->toIso8601String(),
                'motivo_consulta' => $motivos[$index % count($motivos)],
                'modalidad' => 'Presencial',
                'estado' => $estado,
                'fecha_creacion' => $createdAt,
                'fecha_hora_llegada' => in_array($estado, ['Atendida', 'Confirmada'], true) ? $start->copy()->subMinutes(10)->toIso8601String() : null,
                'fecha_hora_registro_atencion' => $estado === 'Atendida' ? $fin->toIso8601String() : null,
                'motivo_cancelacion' => $estado === 'Cancelada' ? 'El paciente reprogramó por motivos personales.' : null,
                'id_paciente' => $patientId,
                'id_medico' => $physicianId,
                'id_especialidad' => $specialtyId,
            ];

            $appointmentId = (int) DB::table('cita_medica')->insertGetId($values, 'id_cita');
            DB::table('evento_cita')->insert([
                'tipo' => 'Programacion',
                'fecha_hora' => $createdAt,
                'motivo' => $values['motivo_consulta'],
                'id_cita' => $appointmentId,
                'id_usuario' => $this->activeAdministratorId(),
            ]);
            $inserted++;
            $index++;
            $hourCursor = $startHour + 1;
            if ($hourCursor > 16 || $index % 4 === 0) {
                $hourCursor = 8;
                $day->addDay();
            }
        }

        return $inserted;
    }

    /**
     * Dense Mon–Sat November slots so detailed status reports for “este mes” have rows.
     *
     * @param  list<int>  $physicianIds
     * @param  array<string, int>  $specialtyIds
     * @param  list<int>  $patientIds
     */
    private function ensureNovemberDetailAppointments(array $physicianIds, array $specialtyIds, array $patientIds): int
    {
        if ($physicianIds === [] || $patientIds === []) {
            return 0;
        }

        $specialtyByPhysician = [
            $physicianIds[0] => $specialtyIds['Medicina general'],
            $physicianIds[1] => $specialtyIds['Pediatría'],
            $physicianIds[2] => $specialtyIds['Ginecología'],
            $physicianIds[3] => $specialtyIds['Cardiología'],
        ];

        $statusCycle = [
            'Atendida', 'Atendida', 'Atendida', 'Atendida',
            'Cancelada', 'Atendida', 'Inasistencia', 'Atendida',
            'Confirmada', 'Atendida', 'Cancelada', 'Programada',
        ];
        $motivos = [
            'Control de presión arterial',
            'Control pediátrico',
            'Consulta ginecológica de rutina',
            'Dolor precordial leve',
            'Control de diabetes',
            'Chequeo anual',
            'Seguimiento de tratamiento',
            'Resfriado prolongado',
        ];

        $inserted = 0;
        $day = Carbon::create(2026, 11, 2, 0, 0, 0, 'UTC');
        $end = Carbon::create(2026, 11, 28, 0, 0, 0, 'UTC');
        $slot = 0;

        while ($day->lte($end)) {
            if ($day->isoWeekday() === 7) {
                $day->addDay();

                continue;
            }

            foreach ($physicianIds as $physicianOffset => $physicianId) {
                foreach ([8, 9, 10, 11, 14, 15, 16] as $hour) {
                    $patientId = $patientIds[$slot % count($patientIds)];
                    $specialtyId = $specialtyByPhysician[$physicianId];
                    $minute = ($slot + $physicianOffset + $hour) % 2 === 0 ? 0 : 30;
                    $start = $day->copy()->setTime($hour, $minute, 0);
                    $fin = $start->copy()->addMinutes(30);
                    $code = sprintf('CVS2-%s-%d%02d%02d', $start->format('ymd'), $physicianOffset + 1, $hour, $minute);
                    $estado = $statusCycle[$slot % count($statusCycle)];

                    if (DB::table('cita_medica')->where('codigo', $code)->exists()) {
                        $slot++;

                        continue;
                    }

                    $overlap = DB::table('cita_medica')
                        ->where('id_medico', $physicianId)
                        ->where('inicio', '<', $fin->toIso8601String())
                        ->where('fin', '>', $start->toIso8601String())
                        ->whereNotIn('estado', ['Cancelada'])
                        ->exists();
                    if ($overlap) {
                        $slot++;

                        continue;
                    }

                    $createdAt = $start->copy()->subDays(1)->toIso8601String();
                    $appointmentId = (int) DB::table('cita_medica')->insertGetId([
                        'codigo' => $code,
                        'inicio' => $start->toIso8601String(),
                        'fin' => $fin->toIso8601String(),
                        'motivo_consulta' => $motivos[$slot % count($motivos)],
                        'modalidad' => 'Presencial',
                        'estado' => $estado,
                        'fecha_creacion' => $createdAt,
                        'fecha_hora_llegada' => in_array($estado, ['Atendida', 'Confirmada'], true)
                            ? $start->copy()->subMinutes(8)->toIso8601String()
                            : null,
                        'fecha_hora_registro_atencion' => $estado === 'Atendida' ? $fin->toIso8601String() : null,
                        'motivo_cancelacion' => $estado === 'Cancelada'
                            ? 'El paciente reprogramó por motivos personales.'
                            : null,
                        'id_paciente' => $patientId,
                        'id_medico' => $physicianId,
                        'id_especialidad' => $specialtyId,
                    ], 'id_cita');
                    DB::table('evento_cita')->insert([
                        'tipo' => 'Programacion',
                        'fecha_hora' => $createdAt,
                        'motivo' => $motivos[$slot % count($motivos)],
                        'id_cita' => $appointmentId,
                        'id_usuario' => $this->activeAdministratorId(),
                    ]);
                    $inserted++;
                    $slot++;
                }
            }

            $day->addDay();
        }

        return $inserted;
    }
}
